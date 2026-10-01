import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SubmitButton } from '@/components/submit-button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { slugify } from '@/lib/slug';
import { createTransactionType } from '@/modules/transaction-entry/entries';

type Values = { key: string; label: string };

/**
 * Creates the first transaction type of a company (TE-FR-01). Until the designer arrives (M5), every
 * type is a `free_lines` type with its first version already published.
 */
export function TransactionTypeForm({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({ defaultValues: { key: '', label: '' } });
    const {
        register,
        formState: { errors, dirtyFields },
    } = form;
    const label = form.watch('label');

    // The key follows the name until the user writes one of their own; clearing it hands it back.
    useEffect(() => {
        if (!dirtyFields.key) {
            form.setValue('key', slugify(label), {
                shouldValidate: form.formState.isSubmitted,
            });
        }
    }, [label, dirtyFields.key]);

    const create = useMutation({
        mutationFn: (values: Values) =>
            createTransactionType(companyId, values),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'transaction-types'],
            });
            toast.success(t('entries.types.created'));
        },
        onError: (error) => {
            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(genericErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                create.mutate(values);
            })}
        >
            <FieldGroup>
                <p className="text-sm text-muted-foreground">
                    {t('entries.types.createDescription')}
                </p>
                {formError && <FormAlert>{formError}</FormAlert>}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.label ? true : undefined}>
                        <FieldLabel htmlFor="type-label">
                            {t('entries.types.label')}
                        </FieldLabel>
                        <Input
                            id="type-label"
                            aria-invalid={errors.label ? true : undefined}
                            {...register('label', {
                                required: t('common.required'),
                            })}
                        />
                        {errors.label && (
                            <FieldError>{errors.label.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.key ? true : undefined}>
                        <FieldLabel htmlFor="type-key">
                            {t('entries.types.key')}
                        </FieldLabel>
                        <Input
                            id="type-key"
                            autoCapitalize="none"
                            aria-invalid={errors.key ? true : undefined}
                            {...register('key', {
                                required: t('common.required'),
                                pattern: {
                                    value: /^[a-z][a-z0-9_]*$/,
                                    message: t('entries.types.keyHint'),
                                },
                            })}
                        />
                        {errors.key ? (
                            <FieldError>{errors.key.message}</FieldError>
                        ) : (
                            <FieldDescription>
                                {t('entries.types.keyHint')}
                            </FieldDescription>
                        )}
                    </Field>
                </div>
                <SubmitButton
                    className="w-auto self-start"
                    pending={create.isPending}
                    pendingLabel={t('entries.types.creating')}
                >
                    {t('entries.types.create')}
                </SubmitButton>
            </FieldGroup>
        </form>
    );
}
