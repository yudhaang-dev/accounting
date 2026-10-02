import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createDimensionValue,
    dimensionValuesQuery,
    updateDimensionValue,
    withDescendants,
    type DimensionType,
    type DimensionValue,
} from '@/modules/master-data/dimensions';

type Values = {
    code: string;
    name: string;
    /** Empty means no parent. */
    parent_id: string;
    /** Empty means from the beginning. */
    valid_from: string;
    /** Empty means without end. */
    valid_to: string;
};

const valuesOf = (value?: DimensionValue): Values => ({
    code: value?.code ?? '',
    name: value?.name ?? '',
    parent_id: value?.parent_id ?? '',
    valid_from: value?.valid_from ?? '',
    valid_to: value?.valid_to ?? '',
});

function DimensionValueForm({
    companyId,
    type,
    value,
    onDone,
}: {
    companyId: string;
    type: DimensionType;
    value?: DimensionValue;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({ defaultValues: valuesOf(value) });
    const {
        register,
        control,
        formState: { errors },
    } = form;
    const siblings = useQuery({
        ...dimensionValuesQuery(companyId, type.id),
        enabled: type.is_hierarchical,
    });

    // A parent is active, of the same type and never the value itself or one of its descendants.
    const excluded = value
        ? withDescendants(siblings.data ?? [], value.id)
        : new Set<string>();
    const parentItems = (siblings.data ?? [])
        .filter(
            (candidate) =>
                candidate.status === 'active' && !excluded.has(candidate.id),
        )
        .map((candidate) => ({
            value: candidate.id,
            label: `${candidate.code} · ${candidate.name}`,
        }));

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'dimension-values'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            value
                ? updateDimensionValue(companyId, value.id, {
                      lock_version: value.lock_version,
                      code: values.code,
                      name: values.name,
                      parent_id: values.parent_id || null,
                      valid_from: values.valid_from || null,
                      valid_to: values.valid_to || null,
                  })
                : createDimensionValue(companyId, {
                      dimension_type_id: type.id,
                      code: values.code,
                      name: values.name,
                      parent_id: values.parent_id || null,
                      valid_from: values.valid_from || null,
                      valid_to: values.valid_to || null,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(
                    value
                        ? 'dimensions.value.saved'
                        : 'dimensions.value.created',
                    {
                        code: saved.code,
                    },
                ),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('dimensions.value.conflict'));

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

                <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                    <Field data-invalid={errors.code ? true : undefined}>
                        <FieldLabel htmlFor="dimension-value-code">
                            {t('dimensions.value.code')}
                        </FieldLabel>
                        <Input
                            id="dimension-value-code"
                            autoFocus
                            aria-invalid={errors.code ? true : undefined}
                            {...register('code', {
                                validate: (v) =>
                                    (v.trim() !== '' && !/\s/.test(v)) ||
                                    t('dimensions.value.codeInvalid'),
                            })}
                        />
                        {errors.code && (
                            <FieldError>{errors.code.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.name ? true : undefined}>
                        <FieldLabel htmlFor="dimension-value-name">
                            {t('dimensions.value.name')}
                        </FieldLabel>
                        <Input
                            id="dimension-value-name"
                            aria-invalid={errors.name ? true : undefined}
                            {...register('name', {
                                validate: (v) =>
                                    v.trim() !== '' || t('common.required'),
                            })}
                        />
                        {errors.name && (
                            <FieldError>{errors.name.message}</FieldError>
                        )}
                    </Field>
                </div>

                {type.is_hierarchical && (
                    <Field data-invalid={errors.parent_id ? true : undefined}>
                        <FieldLabel htmlFor="dimension-value-parent">
                            {t('dimensions.value.parent')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="parent_id"
                            render={({ field }) => (
                                <SearchableSelect
                                    id="dimension-value-parent"
                                    items={parentItems}
                                    value={field.value}
                                    onValueChange={field.onChange}
                                    placeholder={t('dimensions.value.noParent')}
                                    emptyText={t('common.noMatch')}
                                    clearable
                                    aria-invalid={
                                        errors.parent_id ? true : undefined
                                    }
                                />
                            )}
                        />
                        {errors.parent_id && (
                            <FieldError>{errors.parent_id.message}</FieldError>
                        )}
                    </Field>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.valid_from ? true : undefined}>
                        <FieldLabel
                            id="dimension-value-from-label"
                            htmlFor="dimension-value-from"
                        >
                            {t('dimensions.value.validFrom')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="valid_from"
                            render={({ field }) => (
                                <DatePicker
                                    id="dimension-value-from"
                                    labelledBy="dimension-value-from-label"
                                    value={field.value}
                                    onChange={field.onChange}
                                    placeholder={t('dimensions.value.noStart')}
                                    clearable
                                    aria-invalid={
                                        errors.valid_from ? true : undefined
                                    }
                                />
                            )}
                        />
                        {errors.valid_from && (
                            <FieldError>{errors.valid_from.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.valid_to ? true : undefined}>
                        <FieldLabel
                            id="dimension-value-to-label"
                            htmlFor="dimension-value-to"
                        >
                            {t('dimensions.value.validTo')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="valid_to"
                            render={({ field }) => (
                                <DatePicker
                                    id="dimension-value-to"
                                    labelledBy="dimension-value-to-label"
                                    value={field.value}
                                    onChange={field.onChange}
                                    placeholder={t('dimensions.value.noEnd')}
                                    clearable
                                    aria-invalid={
                                        errors.valid_to ? true : undefined
                                    }
                                />
                            )}
                        />
                        {errors.valid_to && (
                            <FieldError>{errors.valid_to.message}</FieldError>
                        )}
                    </Field>
                </div>

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

/** Adds a value to a dimension type, or edits one (MD-FR-03). */
export function DimensionValueDialog({
    companyId,
    type,
    value,
    open,
    onOpenChange,
}: {
    companyId: string;
    type: DimensionType;
    /** The value being edited; none means a new value. */
    value?: DimensionValue;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={
                value
                    ? t('dimensions.value.editTitle', { code: value.code })
                    : t('dimensions.value.createTitle', { type: type.label })
            }
            description={t('dimensions.description')}
        >
            <DimensionValueForm
                key={value?.id ?? 'new'}
                companyId={companyId}
                type={type}
                value={value}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
