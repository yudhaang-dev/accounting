import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PlusIcon, Trash2Icon, XIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Controller,
    useFieldArray,
    FormProvider,
    useForm,
    useFormContext,
    useWatch,
    type Control,
    type UseFormSetError,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError, type ApiError } from '@/lib/http';
import {
    OptionSelect,
    type OptionSelectItem,
} from '@/modules/transaction-entry/components/option-select';
import { lineAccountsQuery } from '@/modules/transaction-entry/entries';
import {
    currencyOptionsQuery,
    dimensionTypeOptionsQuery,
    FIELD_TYPES,
    rateTypeOptionsQuery,
    updateVersion,
    type FieldType,
    type TransactionType,
    type TypeVersion,
    type VersionInput,
    type VersionMode,
} from '@/modules/transaction-entry/transaction-types';

type FieldRow = {
    name: string;
    type: FieldType;
    label: string;
    required: boolean;
    account_ids: string[];
    dimension_type: string;
};

type RuleRow = {
    type: 'amount' | 'fx_difference';
    side: 'debit' | 'credit';
    amount_field: string;
    account_source: 'fixed' | 'field';
    account_id: string;
    account_field: string;
    apply_dimensions: boolean;
    reuses_field: boolean;
};

type Values = {
    mode: VersionMode;
    fields: FieldRow[];
    rules: RuleRow[];
    allowed_currencies: string[];
    rate_type_key: string;
    allow_rate_input: boolean;
};

/** Field types whose values come from the subledger (M6); a version cannot use them yet. */
const SUBLEDGER_TYPES: FieldType[] = ['party', 'cash_account', 'allocations'];

const NAME_PATTERN = /^[a-z][a-z0-9_]*$/;

const blankField = (): FieldRow => ({
    name: '',
    type: 'text',
    label: '',
    required: true,
    account_ids: [],
    dimension_type: '',
});

const blankRule = (): RuleRow => ({
    type: 'amount',
    side: 'debit',
    amount_field: '',
    account_source: 'field',
    account_id: '',
    account_field: '',
    apply_dimensions: true,
    reuses_field: false,
});

export function valuesOfVersion(version: TypeVersion): Values {
    return {
        mode: version.mode,
        fields: (version.fields ?? []).map((field) => ({
            name: field.name,
            type: field.type,
            label: field.label,
            required: field.required ?? true,
            account_ids: field.account_ids ?? [],
            dimension_type: field.dimension_type ?? '',
        })),
        rules: (version.posting_rules ?? []).map((rule) => ({
            type: rule.type,
            side: rule.side ?? 'debit',
            amount_field: rule.amount_field ?? '',
            account_source: rule.account?.source ?? 'field',
            account_id: rule.account?.account_id ?? '',
            account_field: rule.account?.field ?? '',
            apply_dimensions: rule.apply_dimensions ?? false,
            reuses_field: rule.reuses_field ?? false,
        })),
        allowed_currencies: version.allowed_currencies ?? [],
        rate_type_key: version.rate_type_key ?? '',
        allow_rate_input: version.allow_rate_input,
    };
}

/** What the API takes for a version: the screens' rows turned back into definitions. */
export function versionInputOf(values: Values): VersionInput {
    const structured = values.mode === 'structured';

    return {
        mode: values.mode,
        fields: structured
            ? values.fields.map((field) => ({
                  name: field.name.trim(),
                  type: field.type,
                  label: field.label.trim(),
                  required: field.required,
                  ...(field.type === 'account' && field.account_ids.length > 0
                      ? { account_ids: field.account_ids }
                      : {}),
                  ...(field.type === 'dimension' && field.dimension_type
                      ? { dimension_type: field.dimension_type }
                      : {}),
              }))
            : [],
        posting_rules: structured
            ? values.rules.map((rule) =>
                  rule.type === 'fx_difference'
                      ? {
                            type: 'fx_difference' as const,
                            apply_dimensions: rule.apply_dimensions,
                        }
                      : {
                            type: 'amount' as const,
                            side: rule.side,
                            amount_field: rule.amount_field,
                            account:
                                rule.account_source === 'fixed'
                                    ? {
                                          source: 'fixed' as const,
                                          account_id: rule.account_id,
                                      }
                                    : {
                                          source: 'field' as const,
                                          field: rule.account_field,
                                      },
                            apply_dimensions: rule.apply_dimensions,
                            reuses_field: rule.reuses_field,
                        },
              )
            : [],
        allowed_currencies:
            values.allowed_currencies.length > 0
                ? values.allowed_currencies
                : null,
        rate_type_key: values.rate_type_key || null,
        allow_rate_input: values.allow_rate_input,
    };
}

/**
 * Puts the server's errors next to the rows they belong to. The API names a rule `posting_rules.N.…`
 * and its account as a nested object, while the form keeps rules flat as `rules.N.…`.
 */
export function applyVersionErrors(
    setError: UseFormSetError<Values>,
    error: ApiError,
): string[] {
    const unplaced: string[] = [];

    for (const [key, messages] of Object.entries(error.errors)) {
        const message = messages[0];

        if (message === undefined) {
            continue;
        }

        const rule = /^posting_rules\.(\d+)\.(.+)$/.exec(key);
        const target = rule
            ? `rules.${rule[1]}.${
                  rule[2] === 'account.field'
                      ? 'account_field'
                      : rule[2]?.startsWith('account')
                        ? 'account_id'
                        : rule[2]
              }`
            : key.startsWith('allowed_currencies')
              ? 'allowed_currencies'
              : /^fields\.\d+\./.test(key)
                ? key
                : null;

        if (target === null) {
            unplaced.push(message);
        } else {
            setError(target as keyof Values, { type: 'server', message });
        }
    }

    return unplaced;
}

function AccountSet({
    value,
    onChange,
    accounts,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    accounts: OptionSelectItem[];
}) {
    const { t } = useTranslation();
    const label = (id: string) =>
        accounts.find((account) => account.value === id)?.label ?? id;

    return (
        <div className="flex flex-col gap-2">
            {value.length > 0 && (
                <ul className="flex flex-wrap gap-1.5">
                    {value.map((id) => (
                        <li key={id}>
                            <Badge variant="secondary" className="gap-1">
                                {label(id)}
                                <button
                                    type="button"
                                    className="rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-label={t(
                                        'transactionTypes.designer.removeAccount',
                                        { account: label(id) },
                                    )}
                                    onClick={() =>
                                        onChange(
                                            value.filter((item) => item !== id),
                                        )
                                    }
                                >
                                    <XIcon className="size-3" aria-hidden />
                                </button>
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}
            <SearchableSelect
                items={accounts.filter(
                    (account) => !value.includes(account.value),
                )}
                value=""
                onValueChange={(id) => id && onChange([...value, id])}
                placeholder={t('transactionTypes.designer.addAccount')}
                emptyText={t('common.noMatch')}
                aria-label={t('transactionTypes.designer.addAccount')}
            />
        </div>
    );
}

function FieldRows({
    accounts,
    dimensionTypes,
}: {
    accounts: OptionSelectItem[];
    dimensionTypes: OptionSelectItem[];
}) {
    const { t } = useTranslation();
    const {
        control,
        formState: { errors },
        register,
    } = useFormContext<Values>();
    const { fields, append, remove } = useFieldArray({
        control,
        name: 'fields',
    });
    const types = useWatch({ control, name: 'fields' });
    const typeItems = FIELD_TYPES.map((value) => ({
        value,
        label: t(`transactionTypes.fieldTypes.${value}`),
    })).filter(
        (item) =>
            !SUBLEDGER_TYPES.includes(item.value) ||
            types.some((row) => row.type === item.value),
    );

    return (
        <FieldSet>
            <FieldLegend variant="label">
                {t('transactionTypes.designer.fields')}
            </FieldLegend>
            <FieldDescription>
                {t('transactionTypes.designer.fieldsHint')}
            </FieldDescription>
            <ul className="flex flex-col gap-3">
                {fields.map((row, index) => {
                    const n = index + 1;
                    const rowErrors = errors.fields?.[index];
                    const kind = types[index]?.type;

                    return (
                        <li
                            key={row.id}
                            className="flex flex-col gap-2 rounded-lg border p-3"
                        >
                            <div className="grid gap-2 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.6fr)_auto_auto]">
                                <Field
                                    data-invalid={
                                        rowErrors?.name ? true : undefined
                                    }
                                >
                                    <Input
                                        placeholder={t(
                                            'transactionTypes.designer.fieldName',
                                        )}
                                        aria-label={`${t('transactionTypes.designer.fieldName')} ${t('transactionTypes.designer.field', { n })}`}
                                        aria-invalid={
                                            rowErrors?.name ? true : undefined
                                        }
                                        autoCapitalize="none"
                                        className="font-mono"
                                        {...register(`fields.${index}.name`, {
                                            validate: (value) =>
                                                NAME_PATTERN.test(value) ||
                                                t(
                                                    'transactionTypes.designer.fieldNameInvalid',
                                                ),
                                        })}
                                    />
                                </Field>
                                <Controller
                                    control={control}
                                    name={`fields.${index}.type`}
                                    render={({ field }) => (
                                        <OptionSelect
                                            value={field.value}
                                            items={typeItems}
                                            onValueChange={(next) =>
                                                field.onChange(next)
                                            }
                                            aria-label={`${t('transactionTypes.designer.fieldType')} ${t('transactionTypes.designer.field', { n })}`}
                                        />
                                    )}
                                />
                                <Field
                                    data-invalid={
                                        rowErrors?.label ? true : undefined
                                    }
                                >
                                    <Input
                                        placeholder={t(
                                            'transactionTypes.designer.fieldLabel',
                                        )}
                                        aria-label={`${t('transactionTypes.designer.fieldLabel')} ${t('transactionTypes.designer.field', { n })}`}
                                        aria-invalid={
                                            rowErrors?.label ? true : undefined
                                        }
                                        {...register(`fields.${index}.label`, {
                                            validate: (value) =>
                                                value.trim() !== '' ||
                                                t('common.required'),
                                        })}
                                    />
                                </Field>
                                <Controller
                                    control={control}
                                    name={`fields.${index}.required`}
                                    render={({ field }) => (
                                        <Field
                                            orientation="horizontal"
                                            className="items-center"
                                        >
                                            <Checkbox
                                                id={`field-required-${row.id}`}
                                                checked={field.value}
                                                onCheckedChange={(next) =>
                                                    field.onChange(
                                                        next === true,
                                                    )
                                                }
                                            />
                                            <FieldLabel
                                                htmlFor={`field-required-${row.id}`}
                                                className="font-normal"
                                            >
                                                {t(
                                                    'transactionTypes.designer.required',
                                                )}
                                            </FieldLabel>
                                        </Field>
                                    )}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t(
                                        'transactionTypes.designer.removeField',
                                        { n },
                                    )}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2Icon aria-hidden />
                                </Button>
                            </div>
                            {kind === 'account' && (
                                <Field>
                                    <FieldLabel className="text-xs text-muted-foreground">
                                        {t(
                                            'transactionTypes.designer.allowedAccounts',
                                        )}
                                    </FieldLabel>
                                    <Controller
                                        control={control}
                                        name={`fields.${index}.account_ids`}
                                        render={({ field }) => (
                                            <AccountSet
                                                value={field.value}
                                                onChange={field.onChange}
                                                accounts={accounts}
                                            />
                                        )}
                                    />
                                </Field>
                            )}
                            {kind === 'dimension' && (
                                <Field className="max-w-xs">
                                    <FieldLabel className="text-xs text-muted-foreground">
                                        {t(
                                            'transactionTypes.designer.dimensionType',
                                        )}
                                    </FieldLabel>
                                    <Controller
                                        control={control}
                                        name={`fields.${index}.dimension_type`}
                                        rules={{
                                            validate: (value) =>
                                                value !== '' ||
                                                t('common.required'),
                                        }}
                                        render={({ field }) => (
                                            <OptionSelect
                                                value={field.value}
                                                items={dimensionTypes}
                                                placeholder={t(
                                                    'transactionTypes.designer.chooseDimensionType',
                                                )}
                                                onValueChange={field.onChange}
                                                aria-invalid={
                                                    rowErrors?.dimension_type
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                        )}
                                    />
                                </Field>
                            )}
                            {[
                                rowErrors?.name,
                                rowErrors?.label,
                                rowErrors?.account_ids,
                            ].some(Boolean) && (
                                <ul
                                    role="alert"
                                    className="flex flex-col gap-0.5 text-sm text-destructive"
                                >
                                    {[
                                        rowErrors?.name?.message,
                                        rowErrors?.label?.message,
                                        (
                                            rowErrors?.account_ids as
                                                | { message?: string }
                                                | undefined
                                        )?.message,
                                    ]
                                        .filter((m): m is string => Boolean(m))
                                        .map((message) => (
                                            <li key={message}>{message}</li>
                                        ))}
                                </ul>
                            )}
                        </li>
                    );
                })}
            </ul>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => append(blankField())}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('transactionTypes.designer.addField')}
                </Button>
            </div>
        </FieldSet>
    );
}

function RuleRows({ accounts }: { accounts: OptionSelectItem[] }) {
    const { t } = useTranslation();
    const {
        control,
        formState: { errors },
    } = useFormContext<Values>();
    const { fields, append, remove } = useFieldArray({
        control,
        name: 'rules',
    });
    const defined = useWatch({ control, name: 'fields' });
    const rules = useWatch({ control, name: 'rules' });
    const amountFields = defined
        .filter((field) => field.type === 'amount' && field.name !== '')
        .map((field) => ({
            value: field.name,
            label: field.label || field.name,
        }));
    const accountFields = defined
        .filter((field) => field.type === 'account' && field.name !== '')
        .map((field) => ({
            value: field.name,
            label: field.label || field.name,
        }));
    const sideItems = (['debit', 'credit'] as const).map((value) => ({
        value,
        label: t(`transactionTypes.side.${value}`),
    }));
    const kindItems = (['amount', 'fx_difference'] as const).map((value) => ({
        value,
        label: t(`transactionTypes.designer.ruleTypes.${value}`),
    }));
    const sourceItems = (['field', 'fixed'] as const).map((value) => ({
        value,
        label: t(`transactionTypes.designer.accountSources.${value}`),
    }));

    return (
        <FieldSet>
            <FieldLegend variant="label">
                {t('transactionTypes.designer.rules')}
            </FieldLegend>
            <FieldDescription>
                {t('transactionTypes.designer.rulesHint')}
            </FieldDescription>
            <ol className="flex flex-col gap-3">
                {fields.map((row, index) => {
                    const n = index + 1;
                    const rowErrors = errors.rules?.[index];
                    const rule = rules[index];
                    const messages = [
                        rowErrors?.amount_field?.message,
                        rowErrors?.account_id?.message,
                        rowErrors?.account_field?.message,
                        rowErrors?.reuses_field?.message,
                        rowErrors?.type?.message,
                        rowErrors?.side?.message,
                    ].filter((m): m is string => Boolean(m));

                    return (
                        <li
                            key={row.id}
                            className="flex flex-col gap-2 rounded-lg border p-3"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium">
                                    {t('transactionTypes.designer.rule', { n })}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t(
                                        'transactionTypes.designer.removeRule',
                                        { n },
                                    )}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2Icon aria-hidden />
                                </Button>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                <Controller
                                    control={control}
                                    name={`rules.${index}.type`}
                                    render={({ field }) => (
                                        <OptionSelect
                                            value={field.value}
                                            items={kindItems}
                                            onValueChange={field.onChange}
                                            aria-label={`${t('transactionTypes.designer.ruleType')} ${t('transactionTypes.designer.rule', { n })}`}
                                        />
                                    )}
                                />
                                {rule?.type !== 'fx_difference' && (
                                    <>
                                        <Controller
                                            control={control}
                                            name={`rules.${index}.side`}
                                            render={({ field }) => (
                                                <OptionSelect
                                                    value={field.value}
                                                    items={sideItems}
                                                    onValueChange={
                                                        field.onChange
                                                    }
                                                    aria-label={`${t('transactionTypes.designer.side')} ${t('transactionTypes.designer.rule', { n })}`}
                                                />
                                            )}
                                        />
                                        <Controller
                                            control={control}
                                            name={`rules.${index}.amount_field`}
                                            rules={{
                                                validate: (value) =>
                                                    value !== '' ||
                                                    t(
                                                        'transactionTypes.designer.amountFieldRequired',
                                                    ),
                                            }}
                                            render={({ field }) => (
                                                <OptionSelect
                                                    value={field.value}
                                                    items={amountFields}
                                                    placeholder={t(
                                                        'transactionTypes.designer.chooseAmountField',
                                                    )}
                                                    onValueChange={
                                                        field.onChange
                                                    }
                                                    aria-label={`${t('transactionTypes.designer.amountField')} ${t('transactionTypes.designer.rule', { n })}`}
                                                    aria-invalid={
                                                        rowErrors?.amount_field
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                            )}
                                        />
                                        <Controller
                                            control={control}
                                            name={`rules.${index}.account_source`}
                                            render={({ field }) => (
                                                <OptionSelect
                                                    value={field.value}
                                                    items={sourceItems}
                                                    onValueChange={
                                                        field.onChange
                                                    }
                                                    aria-label={`${t('transactionTypes.designer.accountSource')} ${t('transactionTypes.designer.rule', { n })}`}
                                                />
                                            )}
                                        />
                                    </>
                                )}
                            </div>
                            {rule?.type !== 'fx_difference' &&
                                (rule?.account_source === 'fixed' ? (
                                    <Controller
                                        control={control}
                                        name={`rules.${index}.account_id`}
                                        rules={{
                                            validate: (value) =>
                                                value !== '' ||
                                                t('common.required'),
                                        }}
                                        render={({ field }) => (
                                            <SearchableSelect
                                                items={accounts}
                                                value={field.value}
                                                onValueChange={field.onChange}
                                                placeholder={t(
                                                    'transactionTypes.designer.chooseAccount',
                                                )}
                                                emptyText={t('common.noMatch')}
                                                aria-label={`${t('transactionTypes.designer.fixedAccount')} ${t('transactionTypes.designer.rule', { n })}`}
                                                aria-invalid={
                                                    rowErrors?.account_id
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                        )}
                                    />
                                ) : (
                                    <Controller
                                        control={control}
                                        name={`rules.${index}.account_field`}
                                        rules={{
                                            validate: (value) =>
                                                value !== '' ||
                                                t(
                                                    'transactionTypes.designer.accountFieldRequired',
                                                ),
                                        }}
                                        render={({ field }) => (
                                            <OptionSelect
                                                value={field.value}
                                                items={accountFields}
                                                placeholder={t(
                                                    'transactionTypes.designer.chooseAccountField',
                                                )}
                                                onValueChange={field.onChange}
                                                className="w-full sm:w-80"
                                                aria-label={`${t('transactionTypes.designer.accountField')} ${t('transactionTypes.designer.rule', { n })}`}
                                                aria-invalid={
                                                    rowErrors?.account_field
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                        )}
                                    />
                                ))}
                            <div className="flex flex-wrap gap-x-6 gap-y-2">
                                <Controller
                                    control={control}
                                    name={`rules.${index}.apply_dimensions`}
                                    render={({ field }) => (
                                        <Field orientation="horizontal">
                                            <Checkbox
                                                id={`rule-dims-${row.id}`}
                                                checked={field.value}
                                                onCheckedChange={(next) =>
                                                    field.onChange(
                                                        next === true,
                                                    )
                                                }
                                            />
                                            <FieldLabel
                                                htmlFor={`rule-dims-${row.id}`}
                                                className="font-normal"
                                            >
                                                {t(
                                                    'transactionTypes.designer.applyDimensions',
                                                )}
                                            </FieldLabel>
                                        </Field>
                                    )}
                                />
                                {rule?.type !== 'fx_difference' && (
                                    <Controller
                                        control={control}
                                        name={`rules.${index}.reuses_field`}
                                        render={({ field }) => (
                                            <Field orientation="horizontal">
                                                <Checkbox
                                                    id={`rule-reuse-${row.id}`}
                                                    checked={field.value}
                                                    onCheckedChange={(next) =>
                                                        field.onChange(
                                                            next === true,
                                                        )
                                                    }
                                                />
                                                <FieldLabel
                                                    htmlFor={`rule-reuse-${row.id}`}
                                                    className="font-normal"
                                                >
                                                    {t(
                                                        'transactionTypes.designer.reusesField',
                                                    )}
                                                </FieldLabel>
                                            </Field>
                                        )}
                                    />
                                )}
                            </div>
                            {rule?.type === 'fx_difference' && (
                                <p className="text-sm text-muted-foreground">
                                    {t('transactionTypes.designer.fxHint')}
                                </p>
                            )}
                            {messages.length > 0 && (
                                <ul
                                    role="alert"
                                    className="flex flex-col gap-0.5 text-sm text-destructive"
                                >
                                    {[...new Set(messages)].map((message) => (
                                        <li key={message}>{message}</li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    );
                })}
            </ol>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => append(blankRule())}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('transactionTypes.designer.addRule')}
                </Button>
            </div>
        </FieldSet>
    );
}

function CurrencyPolicy({
    control,
    companyId,
}: {
    control: Control<Values>;
    companyId: string;
}) {
    const { t } = useTranslation();
    const currencies = useQuery(currencyOptionsQuery(companyId));
    const rateTypes = useQuery(rateTypeOptionsQuery(companyId));
    const rateItems = [
        {
            value: '__default',
            label: t('transactionTypes.designer.defaultRate'),
        },
        ...(rateTypes.data ?? []).map((type) => ({
            value: type.key,
            label: type.label,
        })),
    ];

    return (
        <FieldSet>
            <FieldLegend variant="label">
                {t('transactionTypes.designer.currencyPolicy')}
            </FieldLegend>
            <FieldDescription>
                {t('transactionTypes.designer.currencyPolicyHint')}
            </FieldDescription>
            <Controller
                control={control}
                name="allowed_currencies"
                render={({ field, fieldState }) => (
                    <Field data-invalid={fieldState.error ? true : undefined}>
                        <ul className="flex flex-wrap gap-x-5 gap-y-2">
                            {(currencies.data ?? []).map((currency) => (
                                <li key={currency.code}>
                                    <Field orientation="horizontal">
                                        <Checkbox
                                            id={`allowed-${currency.code}`}
                                            checked={field.value.includes(
                                                currency.code,
                                            )}
                                            onCheckedChange={(next) =>
                                                field.onChange(
                                                    next === true
                                                        ? [
                                                              ...field.value,
                                                              currency.code,
                                                          ]
                                                        : field.value.filter(
                                                              (code) =>
                                                                  code !==
                                                                  currency.code,
                                                          ),
                                                )
                                            }
                                        />
                                        <FieldLabel
                                            htmlFor={`allowed-${currency.code}`}
                                            className="font-normal"
                                        >
                                            {currency.code}
                                        </FieldLabel>
                                    </Field>
                                </li>
                            ))}
                        </ul>
                        {fieldState.error && (
                            <FieldError>{fieldState.error.message}</FieldError>
                        )}
                    </Field>
                )}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Controller
                    control={control}
                    name="rate_type_key"
                    render={({ field }) => (
                        <Field>
                            <FieldLabel htmlFor="version-rate-type">
                                {t('transactionTypes.designer.rateType')}
                            </FieldLabel>
                            <OptionSelect
                                id="version-rate-type"
                                value={
                                    field.value === ''
                                        ? '__default'
                                        : field.value
                                }
                                items={rateItems}
                                onValueChange={(next) =>
                                    field.onChange(
                                        next === '__default' ? '' : next,
                                    )
                                }
                            />
                        </Field>
                    )}
                />
                <Controller
                    control={control}
                    name="allow_rate_input"
                    render={({ field }) => (
                        <Field
                            orientation="horizontal"
                            className="self-end pb-1.5"
                        >
                            <Checkbox
                                id="version-allow-rate"
                                checked={field.value}
                                onCheckedChange={(next) =>
                                    field.onChange(next === true)
                                }
                            />
                            <div className="flex flex-col gap-0.5">
                                <FieldLabel
                                    htmlFor="version-allow-rate"
                                    className="font-normal"
                                >
                                    {t(
                                        'transactionTypes.designer.allowRateInput',
                                    )}
                                </FieldLabel>
                                <FieldDescription>
                                    {t(
                                        'transactionTypes.designer.allowRateInputHint',
                                    )}
                                </FieldDescription>
                            </div>
                        </Field>
                    )}
                />
            </div>
        </FieldSet>
    );
}

/**
 * Editor of a draft version (TE-FR-02 to TE-FR-06): mode, field schema, posting rules and the currency
 * policy. Nothing here changes a published version; publishing turns the draft into one.
 */
export function VersionEditor({
    companyId,
    type,
    version,
}: {
    companyId: string;
    type: TransactionType;
    version: TypeVersion;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({ defaultValues: valuesOfVersion(version) });
    const { control, handleSubmit } = form;
    const mode = useWatch({ control, name: 'mode' });
    const accounts = useQuery(lineAccountsQuery(companyId));
    const dimensionTypes = useQuery(dimensionTypeOptionsQuery(companyId));
    const accountItems = (accounts.data ?? []).map((account) => ({
        value: account.id,
        label: `${account.code} · ${account.name}`,
    }));

    const save = useMutation({
        mutationFn: (values: Values) =>
            updateVersion(
                companyId,
                type.id,
                version.version,
                versionInputOf(values),
            ),
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'transaction-types'],
            });
            toast.success(t('transactionTypes.designer.saved'));
        },
        onError: (error) => {
            if (isApiError(error) && error.status === 422) {
                const unplaced = applyVersionErrors(form.setError, error);

                if (unplaced.length > 0) {
                    setFormError(unplaced.join(' '));
                }

                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <FormProvider {...form}>
            <form
                noValidate
                onSubmit={handleSubmit((values) => {
                    setFormError(null);
                    save.mutate(values);
                })}
            >
                <FieldGroup>
                    {formError && <FormAlert>{formError}</FormAlert>}

                    <Controller
                        control={control}
                        name="mode"
                        render={({ field }) => (
                            <Field>
                                <FieldLabel id="version-mode-label">
                                    {t('transactionTypes.designer.mode')}
                                </FieldLabel>
                                <ToggleGroup
                                    variant="outline"
                                    aria-labelledby="version-mode-label"
                                    value={[field.value]}
                                    onValueChange={(next) => {
                                        const picked = next[0];

                                        if (
                                            picked === 'free_lines' ||
                                            picked === 'structured'
                                        ) {
                                            field.onChange(picked);
                                        }
                                    }}
                                >
                                    <ToggleGroupItem value="free_lines">
                                        {t('transactionTypes.modes.free_lines')}
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="structured">
                                        {t('transactionTypes.modes.structured')}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                                <FieldDescription>
                                    {t(
                                        `transactionTypes.designer.modeHint.${mode}`,
                                    )}
                                </FieldDescription>
                            </Field>
                        )}
                    />

                    {mode === 'structured' && (
                        <>
                            <FieldRows
                                accounts={accountItems}
                                dimensionTypes={(dimensionTypes.data ?? []).map(
                                    (dimension) => ({
                                        value: dimension.key,
                                        label: dimension.label,
                                    }),
                                )}
                            />
                            <RuleRows accounts={accountItems} />
                        </>
                    )}

                    <CurrencyPolicy control={control} companyId={companyId} />

                    <div>
                        <SubmitButton
                            className="w-auto"
                            pending={save.isPending}
                            pendingLabel={t('common.saving')}
                        >
                            {t('transactionTypes.designer.save')}
                        </SubmitButton>
                    </div>
                </FieldGroup>
            </form>
        </FormProvider>
    );
}
