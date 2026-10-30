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
import { Textarea } from '@/components/ui/textarea';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { slugify } from '@/lib/slug';
import { createTransactionType } from '@/modules/transaction-entry/transaction-types';

type Values = { key: string; label: string; description: string };

/**
 * Creates a transaction type (TE-FR-01). A new type starts as
 * a `free_lines` type with its first version already published; the designer adds versions.
 */
export function TransactionTypeForm({
    companyId,
    onCreated,
}: {
    companyId: string;
    onCreated?: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: { key: '', label: '', description: '' },
    });
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
            createTransactionType(companyId, {
                key: values.key,
                label: values.label,
                description:
                    values.description.trim() === ''
                        ? null
                        : values.description.trim(),
            }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'transaction-types'],
            });
            toast.success(t('entries.types.created'));
            onCreated?.();
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
                {!onCreated && (
                    <p className="text-sm text-muted-foreground">
                        {t('entries.types.createDescription')}
                    </p>
                )}
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
                <Field data-invalid={errors.description ? true : undefined}>
                    <FieldLabel htmlFor="type-description-new">
                        {t('entries.types.descriptionLabel')}
                    </FieldLabel>
                    <Textarea
                        id="type-description-new"
                        rows={2}
                        maxLength={500}
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
