import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    createDimensionType,
    updateDimensionType,
    type DimensionType,
} from '@/modules/master-data/dimensions';

type Values = {
    key: string;
    label: string;
    is_hierarchical: boolean;
    leaf_only: boolean;
};

const valuesOf = (type?: DimensionType): Values => ({
    key: type?.key ?? '',
    label: type?.label ?? '',
    is_hierarchical: type?.is_hierarchical ?? false,
    leaf_only: type?.leaf_only ?? false,
});

function DimensionTypeForm({
    companyId,
    type,
    onDone,
    onSaved,
}: {
    companyId: string;
    type?: DimensionType;
    onDone: () => void;
    onSaved?: (type: DimensionType) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({ defaultValues: valuesOf(type) });
    const {
        register,
        watch,
        setValue,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'dimension-types'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            type
                ? updateDimensionType(companyId, type.id, {
                      lock_version: type.lock_version,
                      label: values.label,
                      is_hierarchical: values.is_hierarchical,
                      leaf_only: values.leaf_only,
                  })
                : createDimensionType(companyId, {
                      key: values.key,
                      label: values.label,
                      is_hierarchical: values.is_hierarchical,
                      leaf_only: values.leaf_only,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(type ? 'dimensions.type.saved' : 'dimensions.type.created', {
                    label: saved.label,
                }),
            );
            onSaved?.(saved);
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('dimensions.type.conflict'));

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
                        <FieldLabel htmlFor="dimension-type-label">
                            {t('dimensions.type.label')}
                        </FieldLabel>
                        <Input
                            id="dimension-type-label"
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
                        <FieldLabel htmlFor="dimension-type-key">
                            {t('dimensions.type.key')}
                        </FieldLabel>
                        <Input
                            id="dimension-type-key"
                            disabled={Boolean(type)}
                            className="font-mono"
                            aria-invalid={errors.key ? true : undefined}
                            {...register('key', {
                                validate: (value) =>
                                    Boolean(type) ||
                                    /^[a-z][a-z0-9_]{0,31}$/.test(value) ||
                                    t('dimensions.type.keyInvalid'),
                            })}
                        />
                        <FieldDescription>
                            {type
                                ? t('dimensions.type.keyLocked')
                                : t('dimensions.type.keyHint')}
                        </FieldDescription>
                        {errors.key && (
                            <FieldError>{errors.key.message}</FieldError>
                        )}
                    </Field>
                </div>

                <Field orientation="horizontal">
                    <Checkbox
                        id="dimension-type-hierarchical"
                        checked={watch('is_hierarchical')}
                        onCheckedChange={(checked) =>
                            setValue('is_hierarchical', checked === true)
                        }
                    />
                    <div className="flex flex-col gap-1">
                        <FieldLabel
                            htmlFor="dimension-type-hierarchical"
                            className="font-normal"
                        >
                            {t('dimensions.type.hierarchical')}
                        </FieldLabel>
                        <FieldDescription>
                            {t('dimensions.type.hierarchicalHint')}
                        </FieldDescription>
                        {errors.is_hierarchical && (
                            <FieldError>
                                {errors.is_hierarchical.message}
                            </FieldError>
                        )}
                    </div>
                </Field>

                <Field orientation="horizontal">
                    <Checkbox
                        id="dimension-type-leaf-only"
                        checked={watch('leaf_only')}
                        onCheckedChange={(checked) =>
                            setValue('leaf_only', checked === true)
                        }
                    />
                    <div className="flex flex-col gap-1">
                        <FieldLabel
                            htmlFor="dimension-type-leaf-only"
                            className="font-normal"
                        >
                            {t('dimensions.type.leafOnly')}
                        </FieldLabel>
                        <FieldDescription>
                            {t('dimensions.type.leafOnlyHint')}
                        </FieldDescription>
                    </div>
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

/** Adds a dimension type, or edits one (MD-FR-02). The key is fixed once the type exists. */
export function DimensionTypeDialog({
    companyId,
    type,
    open,
    onOpenChange,
    onSaved,
}: {
    companyId: string;
    /** The type being edited; none means a new type. */
    type?: DimensionType;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSaved?: (type: DimensionType) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                type
                    ? t('dimensions.type.editTitle', { label: type.label })
                    : t('dimensions.type.createTitle')
            }
            description={t('dimensions.description')}
        >
            <DimensionTypeForm
                key={type?.id ?? 'new'}
                companyId={companyId}
                type={type}
                onSaved={onSaved}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
