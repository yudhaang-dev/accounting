import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { useLastDefined } from '@/hooks/use-last-defined';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { reopenPeriod, type Period } from '@/modules/general-ledger/periods';

function ReopenForm({
    companyId,
    period,
    onDone,
}: {
    companyId: string;
    period: Period;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<{ reason: string }>({ defaultValues: { reason: '' } });
    const {
        register,
        formState: { errors },
    } = form;

    const reopen = useMutation({
        mutationFn: ({ reason }: { reason: string }) =>
            reopenPeriod(companyId, period.id, reason.trim()),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'periods'],
            });
            toast.success(t('periods.reopened', { name: period.name }));
            onDone();
        },
        onError: (error) => {
            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : genericErrorMessage(error, t),
            );
        },
    });

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                reopen.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}
                <Field data-invalid={errors.reason ? true : undefined}>
                    <FieldLabel htmlFor="reopen-reason">
                        {t('periods.reason')}
                    </FieldLabel>
                    <Textarea
                        id="reopen-reason"
                        rows={3}
                        maxLength={500}
                        autoFocus
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
                        {t('periods.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={reopen.isPending}
                        pendingLabel={t('periods.reopening')}
                    >
                        {t('periods.reopenSubmit')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Reopens a closed period; the reason is mandatory and audited (GL-FR-04). */
export function ReopenPeriodDialog({
    companyId,
    period,
    onClose,
}: {
    companyId: string;
    period: Period | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    // Keep the wording while the dialog fades out after `period` is cleared.
    const shown = useLastDefined(period);

    return (
        <Dialog
            open={period !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('periods.reopenTitle', { name: shown?.name ?? '' })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('periods.reopenDescription')}
                    </DialogDescription>
                </DialogHeader>
                {shown && (
                    <ReopenForm
                        key={shown.id}
                        companyId={companyId}
                        period={shown}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
