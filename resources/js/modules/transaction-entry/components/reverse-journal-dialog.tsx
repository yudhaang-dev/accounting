import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { todayIso } from '@/lib/date';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { reverseJournal } from '@/modules/transaction-entry/entries';

type Values = { reversal_date: string; reason: string };

function ReverseForm({
    companyId,
    journalId,
    onDone,
}: {
    companyId: string;
    journalId: string;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: { reversal_date: todayIso(), reason: '' },
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;

    const run = useMutation({
        mutationFn: (values: Values) =>
            reverseJournal(companyId, journalId, {
                reversal_date: values.reversal_date,
                reason: values.reason.trim(),
            }),
        onSuccess: async (entry) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'entries'],
            });
            toast.success(t('reversal.requested'));
            onDone();
            await navigate({
                to: '/w/$companyId/entries/$entryId',
                params: { companyId, entryId: entry.id },
            });
        },
        onError: (error) => {
            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            // Reversed already, or a request is under way: the server says which, in the user's language.
            setFormError(
                isApiError(error) && error.status === 409
                    ? [error.message, ...Object.values(error.errors).flat()]
                          .filter(Boolean)
                          .join(' ')
                    : actionErrorMessage(error, t),
            );
        },
    });

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                run.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <Field data-invalid={errors.reversal_date ? true : undefined}>
                    <FieldLabel
                        id="reversal-date-label"
                        htmlFor="reversal-date"
                    >
                        {t('reversal.date')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="reversal_date"
                        rules={{ required: t('common.required') }}
                        render={({ field }) => (
                            <DatePicker
                                id="reversal-date"
                                labelledBy="reversal-date-label"
                                value={field.value}
                                onChange={field.onChange}
                                aria-invalid={
                                    errors.reversal_date ? true : undefined
                                }
                            />
                        )}
                    />
                    {errors.reversal_date ? (
                        <FieldError>{errors.reversal_date.message}</FieldError>
                    ) : (
                        <FieldDescription>
                            {t('reversal.dateHint')}
                        </FieldDescription>
                    )}
                </Field>

                <Field data-invalid={errors.reason ? true : undefined}>
                    <FieldLabel htmlFor="reversal-reason">
                        {t('reversal.reason')}
                    </FieldLabel>
                    <Textarea
                        id="reversal-reason"
                        rows={3}
                        maxLength={500}
                        aria-invalid={errors.reason ? true : undefined}
                        {...register('reason', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.reason && (
                        <FieldError>{errors.reason.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={run.isPending}
                        pendingLabel={t('reversal.working')}
                    >
                        {t('reversal.submit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/**
 * Asks for the reversal of a posted journal (TE-FR-11): a date whose period accepts the posting and a
 * reason. The answer is a draft that still goes through submit, approval and posting.
 */
export function ReverseJournalDialog({
    companyId,
    journalId,
    journalNumber,
    subject = 'journal',
    open,
    onOpenChange,
}: {
    companyId: string;
    journalId: string;
    journalNumber: string;
    subject?: 'journal' | 'document';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t(
                subject === 'document'
                    ? 'reversal.documentTitle'
                    : 'reversal.title',
                { number: journalNumber },
            )}
            description={t('reversal.description')}
        >
            <p className="text-sm text-muted-foreground">
                {t('reversal.description')}
            </p>
            <ReverseForm
                companyId={companyId}
                journalId={journalId}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
