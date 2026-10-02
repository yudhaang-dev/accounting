import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { FormDialog } from '@/components/form-dialog';
import { SearchableSelect } from '@/components/searchable-select';
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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    activateCurrency,
    isoCurrenciesQuery,
    MAX_SCALE,
    ROUNDING_MODES,
    updateCurrency,
    type Currency,
    type CurrencyRounding,
    type IsoCurrency,
} from '@/modules/master-data/currencies';

type Values = {
    currency: string;
    /** Digits as typed; empty falls back to the ISO minor unit when activating. */
    scale: string;
    rounding_mode: CurrencyRounding;
};

const scaleError = (value: string): boolean =>
    !/^\d$/.test(value) || Number(value) > MAX_SCALE;

function CurrencyForm({
    companyId,
    currency,
    taken,
    iso,
    onDone,
}: {
    companyId: string;
    currency?: Currency;
    /** Codes already activated: they are not offered again. */
    taken: Set<string>;
    iso: IsoCurrency[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            currency: currency?.code ?? '',
            scale: currency ? String(currency.scale) : '',
            rounding_mode:
                (currency?.rounding_mode as CurrencyRounding | undefined) ??
                'half_up',
        },
    });
    const {
        register,
        control,
        setValue,
        watch,
        formState: { errors },
    } = form;
    const chosen = iso.find((item) => item.code === watch('currency'));

    const items = iso
        .filter((item) => !taken.has(item.code))
        .map((item) => ({
            value: item.code,
            label: `${item.code} · ${item.name}`,
        }));
    const roundingItems = ROUNDING_MODES.map((mode) => ({
        value: mode,
        label: t(`currencies.rounding.${mode}`),
    }));

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'currencies'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            currency
                ? updateCurrency(companyId, currency.code, {
                      lock_version: currency.lock_version,
                      scale: Number(values.scale),
                      rounding_mode: values.rounding_mode,
                  })
                : activateCurrency(companyId, {
                      currency: values.currency,
                      scale: values.scale === '' ? null : Number(values.scale),
                      rounding_mode: values.rounding_mode,
                  }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t(
                    currency
                        ? 'currencies.form.saved'
                        : 'currencies.form.activated',
                    { code: saved.code },
                ),
            );
            onDone();
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await refresh();
                setFormError(t('currencies.form.conflict'));

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

                <Field data-invalid={errors.currency ? true : undefined}>
                    <FieldLabel htmlFor="currency-code">
                        {t('currencies.form.currency')}
                    </FieldLabel>
                    {currency ? (
                        <Input
                            id="currency-code"
                            disabled
                            value={`${currency.code} · ${currency.name}`}
                            readOnly
                        />
                    ) : (
                        <Controller
                            control={control}
                            name="currency"
                            rules={{ required: t('common.required') }}
                            render={({ field }) => (
                                <SearchableSelect
                                    id="currency-code"
                                    items={items}
                                    value={field.value}
                                    onValueChange={(code) => {
                                        field.onChange(code);

                                        // The ISO minor unit is the suggestion for the scale.
                                        const picked = iso.find(
                                            (item) => item.code === code,
                                        );

                                        setValue(
                                            'scale',
                                            picked
                                                ? String(picked.minor_unit)
                                                : '',
                                        );
                                    }}
                                    placeholder={t('currencies.form.choose')}
                                    emptyText={t('common.noMatch')}
                                    aria-invalid={
                                        errors.currency ? true : undefined
                                    }
                                />
                            )}
                        />
                    )}
                    {errors.currency && (
                        <FieldError>{errors.currency.message}</FieldError>
                    )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={errors.scale ? true : undefined}>
                        <FieldLabel htmlFor="currency-scale">
                            {t('currencies.form.scale')}
                        </FieldLabel>
                        <Input
                            id="currency-scale"
                            inputMode="numeric"
                            maxLength={1}
                            className="tabular-nums"
                            aria-invalid={errors.scale ? true : undefined}
                            {...register('scale', {
                                validate: (value) =>
                                    (value === '' && !currency) ||
                                    !scaleError(value) ||
                                    t('currencies.form.scaleInvalid', {
                                        max: MAX_SCALE,
                                    }),
                            })}
                        />
                        <FieldDescription>
                            {chosen
                                ? t('currencies.form.scaleHintIso', {
                                      unit: chosen.minor_unit,
                                  })
                                : t('currencies.form.scaleHint')}
                        </FieldDescription>
                        {errors.scale && (
                            <FieldError>{errors.scale.message}</FieldError>
                        )}
                    </Field>

                    <Field
                        data-invalid={errors.rounding_mode ? true : undefined}
                    >
                        <FieldLabel htmlFor="currency-rounding">
                            {t('currencies.form.rounding')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="rounding_mode"
                            render={({ field }) => (
                                <Select
                                    value={field.value}
                                    items={roundingItems}
                                    onValueChange={field.onChange}
                                >
                                    <SelectTrigger
                                        id="currency-rounding"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {roundingItems.map((item) => (
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
                        {errors.rounding_mode && (
                            <FieldError>
                                {errors.rounding_mode.message}
                            </FieldError>
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
                        {currency
                            ? t('common.save')
                            : t('currencies.form.activate')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

function CurrencyFormLoader(props: {
    companyId: string;
    currency?: Currency;
    taken: Set<string>;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const iso = useQuery(isoCurrenciesQuery(props.companyId));

    if (iso.isError) {
        return <FormAlert>{genericErrorMessage(iso.error, t)}</FormAlert>;
    }

    if (iso.data === undefined) {
        return <Skeleton className="h-40 w-full" />;
    }

    return <CurrencyForm {...props} iso={iso.data} />;
}

/**
 * Activates a currency from the ISO 4217 reference, or edits the scale and rounding mode a company
 * uses for one (MD-FR-06). The scale starts at the ISO minor unit.
 */
export function CurrencyDialog({
    companyId,
    currency,
    taken,
    open,
    onOpenChange,
}: {
    companyId: string;
    /** The currency being edited; none means activating a new one. */
    currency?: Currency;
    taken: Set<string>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                currency
                    ? t('currencies.form.editTitle', { code: currency.code })
                    : t('currencies.form.activateTitle')
            }
            description={t('currencies.description')}
        >
            <CurrencyFormLoader
                key={currency?.code ?? 'new'}
                companyId={companyId}
                currency={currency}
                taken={taken}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
