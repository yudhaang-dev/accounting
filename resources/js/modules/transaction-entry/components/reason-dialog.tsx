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
import { isApiError } from '@/lib/http';
import {
    runEntryAction,
    type Entry,
} from '@/modules/transaction-entry/entries';

type Mode = 'reject' | 'cancel';

function ReasonForm({
    companyId,
    entry,
    mode,
    onDone,
}: {
    companyId: string;
    entry: Entry;
    mode: Mode;
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

    const run = useMutation({
        mutationFn: ({ reason }: { reason: string }) =>
            runEntryAction(companyId, entry.id, mode, reason.trim()),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'entries'],
            });
            toast.success(t(`entries.detail.done.${mode}`));
            onDone();
        },
        // The server explains why (the entry moved on meanwhile, no permission), already translated.
        onError: (error) =>
            setFormError(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : genericErrorMessage(error, t),
            ),
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
                <Field data-invalid={errors.reason ? true : undefined}>
                    <FieldLabel htmlFor="entry-reason">
                        {t('entries.reason.label')}
                    </FieldLabel>
                    <Textarea
                        id="entry-reason"
                        rows={3}
                        maxLength={500}
                        autoFocus
                        aria-invalid={errors.reason ? true : undefined}
                        {...register('reason', {
                            // A rejection must say why; a cancellation may stay silent.
                            validate: (value) =>
                                mode === 'cancel' ||
                                value.trim() !== '' ||
                                t('common.required'),
                        })}
                    />
                    {errors.reason && (
                        <FieldError>{errors.reason.message}</FieldError>
                    )}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('entries.reason.back')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        variant={mode === 'reject' ? 'destructive' : 'default'}
                        pending={run.isPending}
                        pendingLabel={t('entries.reason.working')}
                    >
                        {mode === 'reject'
                            ? t('entries.reason.submitReject')
                            : t('entries.reason.submitCancel')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Asks for the reason of a rejection (required) or a cancellation (optional), TE-FR-07. */
export function ReasonDialog({
    companyId,
    entry,
    mode,
    onClose,
}: {
    companyId: string;
    entry: Entry;
    mode: Mode | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    // Keep the wording while the dialog fades out after `mode` is cleared.
    const shown = useLastDefined(mode);

    return (
        <Dialog
            open={mode !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t(
                            shown === 'cancel'
                                ? 'entries.reason.cancelTitle'
                                : 'entries.reason.rejectTitle',
                            { number: entry.number },
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            shown === 'cancel'
                                ? 'entries.reason.cancelDescription'
                                : 'entries.reason.rejectDescription',
                        )}
                    </DialogDescription>
                </DialogHeader>
                {shown && (
                    <ReasonForm
                        key={shown}
                        companyId={companyId}
                        entry={entry}
                        mode={shown}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
