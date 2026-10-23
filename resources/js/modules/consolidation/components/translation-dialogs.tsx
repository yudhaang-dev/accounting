import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { DatePicker } from '@/components/date-picker';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { MoneyInput } from '@/components/money-input';
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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { todayIso } from '@/lib/date';
import { isPositive } from '@/lib/decimal';
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    createRateType,
    recordGroupRate,
    updateRateType,
    type RateType,
} from '@/modules/consolidation/groups';

/** A rate is kept to the same twelve places the API takes (API-TR-05). */
const RATE_DECIMALS = 12;

type TypeValues = { code: string; name: string; is_active: boolean };

function RateTypeForm({
    groupId,
    type,
    onDone,
}: {
    groupId: string;
    type?: RateType;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const editing = type !== undefined;
    const form = useForm<TypeValues>({
        defaultValues: {
            code: type?.code ?? '',
            name: type?.name ?? '',
            is_active: type?.is_active ?? true,
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['groups', groupId, 'rate-types'],
        });

    const save = useMutation({
        mutationFn: (values: TypeValues) =>
            type
                ? updateRateType(groupId, type, {
                      name: values.name.trim(),
                      is_active: values.is_active,
                  })
                : createRateType(groupId, {
                      code: values.code.trim(),
                      name: values.name.trim(),
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(
                    editing
                        ? 'consolidation.translation.types.saved'
                        : 'consolidation.translation.types.created',
                    { name: saved.name },
                ),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('consolidation.conflict'));

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

                <Field data-invalid={errors.code ? true : undefined}>
                    <FieldLabel htmlFor="rate-type-code">
                        {t('consolidation.translation.types.code')}
                    </FieldLabel>
                    <Input
                        id="rate-type-code"
                        autoFocus={!editing}
                        disabled={editing}
                        aria-invalid={errors.code ? true : undefined}
                        {...register('code', {
                            validate: (value) =>
                                editing ||
                                /^[a-z][a-z0-9_]*$/.test(value.trim()) ||
                                t(
                                    'consolidation.translation.types.codeInvalid',
                                ),
                        })}
                    />
                    <FieldDescription>
                        {t('consolidation.translation.types.codeHint')}
                    </FieldDescription>
                    {errors.code && (
                        <FieldError>{errors.code.message}</FieldError>
                    )}
                </Field>

                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="rate-type-name">
                        {t('consolidation.translation.types.name')}
                    </FieldLabel>
                    <Input
                        id="rate-type-name"
                        autoFocus={editing}
                        aria-invalid={errors.name ? true : undefined}
                        {...register('name', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.name && (
                        <FieldError>{errors.name.message}</FieldError>
                    )}
                </Field>

                {editing && (
                    <Field orientation="horizontal">
                        <Controller
                            control={control}
                            name="is_active"
                            render={({ field }) => (
                                <Checkbox
                                    id="rate-type-active"
                                    checked={field.value}
                                    onCheckedChange={(next) =>
                                        field.onChange(next === true)
                                    }
                                />
                            )}
                        />
                        <FieldLabel
                            htmlFor="rate-type-active"
                            className="font-normal"
                        >
                            {t('consolidation.translation.types.active')}
                        </FieldLabel>
                    </Field>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
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

/** Creates a rate type of the group, or renames or deactivates one; its code never changes. */
export function RateTypeDialog({
    groupId,
    open,
    type,
    onOpenChange,
}: {
    groupId: string;
    open: boolean;
    /** The type to change; none to create one. */
    type?: RateType;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t(
                type
                    ? 'consolidation.translation.types.editTitle'
                    : 'consolidation.translation.types.newTitle',
            )}
            description={t('consolidation.translation.types.dialogDescription')}
        >
            <RateTypeForm
                groupId={groupId}
                type={type}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}

type RateValues = {
    rate_type_id: string;
    currency: string;
    effective_date: string;
    rate: string;
};

function RateForm({
    groupId,
    types,
    presentationCurrency,
    onDone,
}: {
    groupId: string;
    types: RateType[];
    presentationCurrency: string;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<RateValues>({
        defaultValues: {
            rate_type_id: types[0]?.id ?? '',
            currency: '',
            effective_date: todayIso(),
            rate: '',
        },
    });
    const {
        control,
        register,
        formState: { errors },
    } = form;
    const items = types.map((type) => ({ value: type.id, label: type.name }));

    const save = useMutation({
        mutationFn: (values: RateValues) =>
            recordGroupRate(groupId, {
                ...values,
                currency: values.currency.trim().toUpperCase(),
            }),
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({
                queryKey: ['groups', groupId, 'rates'],
            });
            toast.success(
                t('consolidation.translation.rates.saved', {
                    currency: saved.currency,
                }),
            );
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

                <Field data-invalid={errors.rate_type_id ? true : undefined}>
                    <FieldLabel htmlFor="group-rate-type">
                        {t('consolidation.translation.rates.type')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="rate_type_id"
                        render={({ field }) => (
                            <Select
                                value={field.value}
                                items={items}
                                onValueChange={(value) =>
                                    field.onChange(value ?? '')
                                }
                            >
                                <SelectTrigger id="group-rate-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {items.map((item) => (
                                            <SelectItem
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {item.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        )}
                    />
                    {errors.rate_type_id && (
                        <FieldError>{errors.rate_type_id.message}</FieldError>
                    )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.currency ? true : undefined}>
                        <FieldLabel htmlFor="group-rate-currency">
                            {t('consolidation.translation.rates.currency')}
                        </FieldLabel>
                        <Input
                            id="group-rate-currency"
                            maxLength={3}
                            autoCapitalize="characters"
                            aria-invalid={errors.currency ? true : undefined}
                            {...register('currency', {
                                validate: (value) =>
                                    /^[A-Za-z]{3}$/.test(value.trim()) ||
                                    t(
                                        'consolidation.translation.rates.currencyInvalid',
                                    ),
                            })}
                        />
                        {errors.currency && (
                            <FieldError>{errors.currency.message}</FieldError>
                        )}
                    </Field>

                    <Field
                        data-invalid={errors.effective_date ? true : undefined}
                    >
                        <FieldLabel id="group-rate-date-label">
                            {t('consolidation.translation.rates.date')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="effective_date"
                            rules={{
                                validate: (value) =>
                                    value !== '' || t('common.required'),
                            }}
                            render={({ field }) => (
                                <DatePicker
                                    labelledBy="group-rate-date-label"
                                    value={field.value}
                                    onChange={field.onChange}
                                    aria-invalid={
                                        errors.effective_date ? true : undefined
                                    }
                                />
                            )}
                        />
                        {errors.effective_date && (
                            <FieldError>
                                {errors.effective_date.message}
                            </FieldError>
                        )}
                    </Field>
                </div>

                <Field data-invalid={errors.rate ? true : undefined}>
                    <FieldLabel htmlFor="group-rate-value">
                        {t('consolidation.translation.rates.rate')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="rate"
                        rules={{
                            validate: (value) =>
                                isPositive(value) ||
                                t(
                                    'consolidation.translation.rates.rateInvalid',
                                ),
                        }}
                        render={({ field }) => (
                            <MoneyInput
                                id="group-rate-value"
                                maxDecimals={RATE_DECIMALS}
                                value={field.value}
                                onBlur={field.onBlur}
                                onChange={field.onChange}
                                aria-invalid={errors.rate ? true : undefined}
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('consolidation.translation.rates.rateHint', {
                            currency: presentationCurrency,
                        })}
                    </FieldDescription>
                    {errors.rate && (
                        <FieldError>{errors.rate.message}</FieldError>
                    )}
                </Field>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <SubmitButton
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

/** Records a rate for a type, a currency and a date; a different rate for the same three is a correction (a new version). */
export function GroupRateDialog({
    groupId,
    open,
    types,
    presentationCurrency,
    onOpenChange,
}: {
    groupId: string;
    open: boolean;
    types: RateType[];
    presentationCurrency: string;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('consolidation.translation.rates.newTitle')}
            description={t('consolidation.translation.rates.dialogDescription')}
        >
            <RateForm
                groupId={groupId}
                types={types}
                presentationCurrency={presentationCurrency}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
