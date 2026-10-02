import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { isPositive } from '@/lib/decimal';
import { todayIso } from '@/lib/date';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { currenciesQuery } from '@/modules/master-data/currencies';
import {
    rateTypesQuery,
    RATE_DECIMALS,
    recordRate,
    type ExchangeRate,
    type RateType,
} from '@/modules/master-data/exchange-rates';
import type { Currency } from '@/modules/master-data/currencies';

type Values = {
    rate_type: string;
    currency: string;
    effective_date: string;
    rate: string;
};

function ExchangeRateForm({
    companyId,
    correcting,
    types,
    currencies,
    onDone,
}: {
    companyId: string;
    /** The rate being corrected: its type, currency and date stay, only the rate changes. */
    correcting?: ExchangeRate;
    types: RateType[];
    currencies: Currency[];
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            rate_type: correcting?.rate_type ?? types[0]?.key ?? '',
            currency: correcting?.currency ?? currencies[0]?.code ?? '',
            effective_date: correcting?.effective_date ?? todayIso(),
            rate: correcting?.rate ?? '',
        },
    });
    const {
        control,
        formState: { errors },
    } = form;

    const typeItems = types.map((type) => ({
        value: type.key,
        label: type.label,
    }));
    const currencyItems = currencies.map((currency) => ({
        value: currency.code,
        label: `${currency.code} · ${currency.name}`,
    }));

    const save = useMutation({
        mutationFn: (values: Values) => recordRate(companyId, values),
        onSuccess: async ({ rate, created }) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'exchange-rates'],
            });
            toast.success(
                t(
                    !created
                        ? 'rates.form.unchanged'
                        : rate.version > 1
                          ? 'rates.form.corrected'
                          : 'rates.form.recorded',
                    { currency: rate.currency, version: rate.version },
                ),
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

    const lockedSelect = (
        name: 'rate_type' | 'currency',
        items: { value: string; label: string }[],
        label: string,
        id: string,
    ) => (
        <Field data-invalid={errors[name] ? true : undefined}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Controller
                control={control}
                name={name}
                rules={{ required: t('common.required') }}
                render={({ field }) => (
                    <Select
                        value={field.value}
                        items={items}
                        disabled={Boolean(correcting)}
                        onValueChange={field.onChange}
                    >
                        <SelectTrigger id={id} className="w-full">
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
            {errors[name] && <FieldError>{errors[name]?.message}</FieldError>}
        </Field>
    );

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
                {correcting && (
                    <p className="text-sm text-muted-foreground">
                        {t('rates.form.correctionNote', {
                            version: correcting.version + 1,
                        })}
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    {lockedSelect(
                        'rate_type',
                        typeItems,
                        t('rates.form.rateType'),
                        'rate-type',
                    )}
                    {lockedSelect(
                        'currency',
                        currencyItems,
                        t('rates.form.currency'),
                        'rate-currency',
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        data-invalid={errors.effective_date ? true : undefined}
                    >
                        <FieldLabel id="rate-date-label" htmlFor="rate-date">
                            {t('rates.form.effectiveDate')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="effective_date"
                            rules={{ required: t('common.required') }}
                            render={({ field }) => (
                                <DatePicker
                                    id="rate-date"
                                    labelledBy="rate-date-label"
                                    value={field.value}
                                    onChange={field.onChange}
                                    disabled={Boolean(correcting)}
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

                    <Field data-invalid={errors.rate ? true : undefined}>
                        <FieldLabel htmlFor="rate-value">
                            {t('rates.form.rate')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="rate"
                            rules={{
                                validate: (value) =>
                                    isPositive(value) ||
                                    t('rates.form.rateInvalid'),
                            }}
                            render={({ field }) => (
                                <MoneyInput
                                    id="rate-value"
                                    autoFocus={Boolean(correcting)}
                                    maxDecimals={RATE_DECIMALS}
                                    value={field.value}
                                    onBlur={field.onBlur}
                                    onChange={field.onChange}
                                    aria-invalid={
                                        errors.rate ? true : undefined
                                    }
                                />
                            )}
                        />
                        <FieldDescription>
                            {t('rates.form.rateHint')}
                        </FieldDescription>
                        {errors.rate && (
                            <FieldError>{errors.rate.message}</FieldError>
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
                        {correcting
                            ? t('rates.form.correct')
                            : t('rates.form.record')}
                    </SubmitButton>
                </DialogFooter>
            </FieldGroup>
        </form>
    );
}

function ExchangeRateFormLoader(props: {
    companyId: string;
    correcting?: ExchangeRate;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const types = useQuery(rateTypesQuery(props.companyId));
    const currencies = useQuery(currenciesQuery(props.companyId));

    if (types.isError || currencies.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(types.error ?? currencies.error, t)}
            </FormAlert>
        );
    }

    if (types.data === undefined || currencies.data === undefined) {
        return <Skeleton className="h-48 w-full" />;
    }

    // A rate is recorded for an active type and an active foreign currency; a corrected rate keeps its own.
    const usableTypes = types.data.filter(
        (type) =>
            type.status === 'active' ||
            type.key === props.correcting?.rate_type,
    );
    const usableCurrencies = currencies.data.filter(
        (currency) =>
            (currency.status === 'active' && !currency.is_functional) ||
            currency.code === props.correcting?.currency,
    );

    if (usableTypes.length === 0 || usableCurrencies.length === 0) {
        return (
            <FormAlert>
                {usableTypes.length === 0
                    ? t('rates.form.noTypes')
                    : t('rates.form.noCurrencies')}
            </FormAlert>
        );
    }

    return (
        <ExchangeRateForm
            {...props}
            types={usableTypes}
            currencies={usableCurrencies}
        />
    );
}

/**
 * Records the rate of a foreign currency for a rate type and a date (MD-FR-07), or corrects one: a
 * correction is a new version, so the old rate stays in the history.
 */
export function ExchangeRateDialog({
    companyId,
    correcting,
    open,
    onOpenChange,
}: {
    companyId: string;
    correcting?: ExchangeRate;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                correcting
                    ? t('rates.form.correctTitle', {
                          currency: correcting.currency,
                          date: correcting.effective_date,
                      })
                    : t('rates.form.recordTitle')
            }
            description={t('rates.description')}
        >
            <ExchangeRateFormLoader
                key={correcting?.id ?? 'new'}
                companyId={companyId}
                correcting={correcting}
                onDone={() => onOpenChange(false)}
            />
        </FormDialog>
    );
}
