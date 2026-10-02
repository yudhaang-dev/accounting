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
import { Input } from '@/components/ui/input';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createRateType,
    updateRateType,
    type RateType,
} from '@/modules/master-data/exchange-rates';

type Values = {
    key: string;
    label: string;
    /** Days as typed; empty means no limit. */
    max_age_days: string;
};

function RateTypeForm({
    companyId,
    type,
    onDone,
}: {
    companyId: string;
    type?: RateType;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            key: type?.key ?? '',
            label: type?.label ?? '',
            max_age_days:
                type?.max_age_days == null ? '' : String(type.max_age_days),
        },
    });
    const {
        register,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'rate-types'],
        });

    const save = useMutation({
        mutationFn: (values: Values) => {
            const maxAge =
                values.max_age_days === '' ? null : Number(values.max_age_days);

            return type
                ? updateRateType(companyId, type.id, {
                      lock_version: type.lock_version,
                      label: values.label,
                      max_age_days: maxAge,
                  })
                : createRateType(companyId, {
                      key: values.key,
                      label: values.label,
                      max_age_days: maxAge,
                  });
        },
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(type ? 'rates.type.saved' : 'rates.type.created', {
                    label: saved.label,
                }),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('rates.type.conflict'));

                return;
            }

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

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.label ? true : undefined}>
                        <FieldLabel htmlFor="rate-type-label">
                            {t('rates.type.label')}
                        </FieldLabel>
                        <Input
                            id="rate-type-label"
                            autoFocus
                            aria-invalid={errors.label ? true : undefined}
                            {...register('label', {
                                validate: (value) =>
                                    value.trim() !== '' || t('common.required'),
                            })}
                        />
                        {errors.label && (
                            <FieldError>{errors.label.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.key ? true : undefined}>
                        <FieldLabel htmlFor="rate-type-key">
                            {t('rates.type.key')}
                        </FieldLabel>
                        <Input
                            id="rate-type-key"
                            disabled={Boolean(type)}
                            className="font-mono"
                            aria-invalid={errors.key ? true : undefined}
                            {...register('key', {
                                validate: (value) =>
                                    Boolean(type) ||
                                    /^[a-z][a-z0-9_]{0,31}$/.test(value) ||
                                    t('rates.type.keyInvalid'),
                            })}
                        />
                        <FieldDescription>
                            {type
                                ? t('rates.type.keyLocked')
                                : t('rates.type.keyHint')}
                        </FieldDescription>
                        {errors.key && (
                            <FieldError>{errors.key.message}</FieldError>
                        )}
                    </Field>
                </div>

                <Field data-invalid={errors.max_age_days ? true : undefined}>
                    <FieldLabel htmlFor="rate-type-max-age">
                        {t('rates.type.maxAge')}
                    </FieldLabel>
                    <Input
                        id="rate-type-max-age"
                        inputMode="numeric"
                        className="w-32 tabular-nums"
                        aria-invalid={errors.max_age_days ? true : undefined}
                        {...register('max_age_days', {
                            validate: (value) =>
                                value === '' ||
                                (/^\d{1,4}$/.test(value) &&
                                    Number(value) <= 3650) ||
                                t('rates.type.maxAgeInvalid'),
                        })}
                    />
                    <FieldDescription>
                        {t('rates.type.maxAgeHint')}
                    </FieldDescription>
                    {errors.max_age_days && (
                        <FieldError>{errors.max_age_days.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

/** Adds a rate type, or edits one (MD-FR-07). The key is fixed once the type exists. */
export function RateTypeDialog({
    companyId,
    type,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The type being edited; none means a new type. */
    type?: RateType;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                type
                    ? t('rates.type.editTitle', { label: type.label })
                    : t('rates.type.createTitle')
            }
            description={t('rates.types.description')}
        >
            <RateTypeForm
                key={type?.id ?? 'new'}
                companyId={companyId}
                type={type}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
