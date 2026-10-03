import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import {
    Controller,
    useFormState,
    useWatch,
    type UseFormReturn,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { DatePicker } from '@/components/date-picker';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { Values } from '@/modules/transaction-entry/components/entry-values';
import { OptionSelect } from '@/modules/transaction-entry/components/option-select';
import type {
    LineAccount,
    LineDimensionType,
} from '@/modules/transaction-entry/entries';
import type { SchemaField } from '@/modules/transaction-entry/field-schema';
import {
    currencyOptionsQuery,
    type TypeVersion,
} from '@/modules/transaction-entry/transaction-types';

/** An exchange rate keeps more decimals than an amount does. */
const RATE_DECIMALS = 12;

function FieldInput({
    field,
    form,
    accounts,
    dimensionTypes,
}: {
    field: SchemaField;
    form: UseFormReturn<Values>;
    accounts: LineAccount[];
    dimensionTypes: LineDimensionType[];
}) {
    const { t } = useTranslation();
    const { control, register } = form;
    const name = `values.${field.name}` as const;
    const { errors } = useFormState({ control, name: 'values' });
    const error = errors.values?.[field.name];
    const id = `entry-field-${field.name}`;
    const rules = {
        validate: (value: string | undefined) =>
            !field.required ||
            (value ?? '').trim() !== '' ||
            t('common.required'),
    };

    if (field.kind === 'unsupported') {
        return (
            <Field className="sm:col-span-2">
                <FieldLabel>{field.label}</FieldLabel>
                <FieldDescription>
                    {t('entries.form.unsupportedField')}
                </FieldDescription>
            </Field>
        );
    }

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel id={`${id}-label`} htmlFor={id}>
                {field.label}
                {!field.required && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        ({t('entries.form.optional')})
                    </span>
                )}
            </FieldLabel>

            {field.kind === 'amount' && (
                <Controller
                    control={control}
                    name={name}
                    rules={rules}
                    render={({ field: money }) => (
                        <MoneyInput
                            id={id}
                            value={money.value ?? ''}
                            onBlur={money.onBlur}
                            onChange={money.onChange}
                            aria-invalid={error ? true : undefined}
                        />
                    )}
                />
            )}

            {field.kind === 'date' && (
                <Controller
                    control={control}
                    name={name}
                    rules={rules}
                    render={({ field: date }) => (
                        <DatePicker
                            id={id}
                            labelledBy={`${id}-label`}
                            value={date.value ?? ''}
                            onChange={date.onChange}
                            clearable={!field.required}
                            aria-invalid={error ? true : undefined}
                        />
                    )}
                />
            )}

            {(field.kind === 'text' || field.kind === 'reference') && (
                <Input
                    id={id}
                    maxLength={field.maxLength}
                    aria-invalid={error ? true : undefined}
                    {...register(name, rules)}
                />
            )}

            {field.kind === 'account' && (
                <Controller
                    control={control}
                    name={name}
                    rules={rules}
                    render={({ field: account }) => (
                        <SearchableSelect
                            id={id}
                            items={accounts
                                .filter(
                                    (item) =>
                                        !field.accountIds ||
                                        field.accountIds.includes(item.id),
                                )
                                .map((item) => ({
                                    value: item.id,
                                    label: `${item.code} · ${item.name}`,
                                }))}
                            value={account.value ?? ''}
                            onValueChange={account.onChange}
                            placeholder={t('entries.form.chooseAccount')}
                            emptyText={t('common.noMatch')}
                            clearable={!field.required}
                            aria-invalid={error ? true : undefined}
                        />
                    )}
                />
            )}

            {field.kind === 'dimension' && (
                <Controller
                    control={control}
                    name={name}
                    rules={rules}
                    render={({ field: dimension }) => (
                        <SearchableSelect
                            id={id}
                            items={(
                                dimensionTypes.find(
                                    (type) => type.key === field.dimensionType,
                                )?.values ?? []
                            ).map((value) => ({
                                value: value.id,
                                label: `${value.code} · ${value.name}`,
                            }))}
                            value={dimension.value ?? ''}
                            onValueChange={dimension.onChange}
                            placeholder={t('entries.form.chooseDimension')}
                            emptyText={t('common.noMatch')}
                            clearable={!field.required}
                            aria-invalid={error ? true : undefined}
                        />
                    )}
                />
            )}

            {error && <FieldError>{error.message}</FieldError>}
        </Field>
    );
}

/**
 * Currency and rate of a structured entry (TE-FR-06): one currency for the whole entry, from those the
 * version allows. The rate is typed only when the version lets the user and the currency is not the
 * functional one; left empty, the server takes it from the rate table.
 */
function EntryCurrency({
    companyId,
    version,
    form,
}: {
    companyId: string;
    version: TypeVersion;
    form: UseFormReturn<Values>;
}) {
    const { t } = useTranslation();
    const currencies = useQuery(currencyOptionsQuery(companyId));
    const { control } = form;
    const functional = currencies.data?.find(
        (currency) => currency.is_functional,
    );
    const allowed = version.allowed_currencies;
    const codes = allowed?.length
        ? allowed
        : (currencies.data ?? []).map((currency) => currency.code);
    const current = useWatch({ control, name: 'currency' });
    const effective = current || functional?.code || '';
    const { errors } = useFormState({ control });

    // A version that does not allow the functional currency has no "empty" choice: start on its first one.
    useEffect(() => {
        if (
            functional &&
            allowed?.length &&
            !allowed.includes(functional.code) &&
            current === ''
        ) {
            form.setValue('currency', allowed[0] ?? '');
        }
    }, [functional?.code, allowed?.join(','), current]);

    if (codes.length <= 1 && !version.allow_rate_input) {
        return null;
    }

    const items = codes.map((code) => ({ value: code, label: code }));
    const askRate =
        version.allow_rate_input &&
        functional !== undefined &&
        effective !== '' &&
        effective !== functional.code;

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {codes.length > 1 && (
                <Field data-invalid={errors.currency ? true : undefined}>
                    <FieldLabel htmlFor="entry-currency">
                        {t('entries.form.currency')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="currency"
                        render={({ field }) => (
                            <OptionSelect
                                id="entry-currency"
                                value={effective}
                                items={items}
                                onValueChange={(next) =>
                                    field.onChange(
                                        next === functional?.code ? '' : next,
                                    )
                                }
                                aria-invalid={
                                    errors.currency ? true : undefined
                                }
                            />
                        )}
                    />
                    {errors.currency && (
                        <FieldError>{errors.currency.message}</FieldError>
                    )}
                </Field>
            )}
            {askRate && (
                <Field data-invalid={errors.exchange_rate ? true : undefined}>
                    <FieldLabel htmlFor="entry-rate">
                        {t('entries.form.rate', {
                            from: effective,
                            to: functional?.code,
                        })}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="exchange_rate"
                        render={({ field }) => (
                            <MoneyInput
                                id="entry-rate"
                                value={field.value}
                                onBlur={field.onBlur}
                                onChange={field.onChange}
                                maxDecimals={RATE_DECIMALS}
                                aria-invalid={
                                    errors.exchange_rate ? true : undefined
                                }
                            />
                        )}
                    />
                    {errors.exchange_rate ? (
                        <FieldError>{errors.exchange_rate.message}</FieldError>
                    ) : (
                        <FieldDescription>
                            {t('entries.form.rateHint')}
                        </FieldDescription>
                    )}
                </Field>
            )}
        </div>
    );
}

/**
 * The inputs of a structured entry, drawn from the field schema the API publishes for the version
 * (TE-FR-04): the same schema an integration client validates against. The journal lines are not typed
 * here; the posting rules make them.
 */
export function StructuredSection({
    companyId,
    version,
    fields,
    form,
    accounts,
    dimensionTypes,
}: {
    companyId: string;
    version: TypeVersion;
    fields: SchemaField[];
    form: UseFormReturn<Values>;
    accounts: LineAccount[];
    dimensionTypes: LineDimensionType[];
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                {fields.map((field) => (
                    <FieldInput
                        key={field.name}
                        field={field}
                        form={form}
                        accounts={accounts}
                        dimensionTypes={dimensionTypes}
                    />
                ))}
            </div>
            <EntryCurrency
                companyId={companyId}
                version={version}
                form={form}
            />
        </div>
    );
}
