import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
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
import { useLastDefined } from '@/hooks/use-last-defined';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    setDescription,
    type TransactionType,
} from '@/modules/transaction-entry/transaction-types';

/** The longest description the API keeps. */
export const DESCRIPTION_MAX = 500;

type Values = { description: string };

function DescriptionForm({
    companyId,
    type,
    onDone,
}: {
    companyId: string;
    type: TransactionType;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: { description: type.description ?? '' },
    });
    const {
        register,
        formState: { errors },
    } = form;

    const save = useMutation({
        mutationFn: (values: Values) =>
            setDescription(
                companyId,
                type.id,
                values.description.trim() === ''
                    ? null
                    : values.description.trim(),
            ),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'transaction-types'],
            });
            toast.success(t('entries.types.descriptionSaved'));
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

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                save.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}
                <Field data-invalid={errors.description ? true : undefined}>
                    <FieldLabel htmlFor="type-description">
                        {t('entries.types.descriptionLabel')}
                    </FieldLabel>
                    <Textarea
                        id="type-description"
                        autoFocus
                        rows={3}
                        maxLength={DESCRIPTION_MAX}
                        aria-invalid={errors.description ? true : undefined}
                        {...register('description')}
                    />
                    {errors.description ? (
                        <FieldError>{errors.description.message}</FieldError>
                    ) : (
                        <FieldDescription>
                            {t('entries.types.descriptionHint')}
                        </FieldDescription>
                    )}
                </Field>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('entries.types.descriptionSaving')}
                    >
                        {t('entries.types.descriptionSave')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** What a transaction type is for, written by the accountant for the catalog an operator picks from (TE-FR-01). */
export function DescriptionDialog({
    companyId,
    type,
    onClose,
}: {
    companyId: string;
    /** The type being described; none keeps the dialog closed. */
    type: TransactionType | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const shown = useLastDefined(type);

    return (
        <FormDialog
            open={type !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={shown?.label ?? ''}
            description={t('entries.types.descriptionHint')}
        >
            {type && (
                <DescriptionForm
                    key={type.id}
                    companyId={companyId}
                    type={type}
                    onDone={onClose}
                />
            )}
        </FormDialog>
    );
}
