import { useQuery } from '@tanstack/react-query';
import { Controller, useWatch, type Control } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { SearchableSelect } from '@/components/searchable-select';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    CURRENCY_RESTRICTIONS,
    DIMENSION_REQUIREMENTS,
    POSTING_SOURCES,
    type CurrencyRestriction,
    type DimensionRequirement,
    type PostingSource,
} from '@/modules/general-ledger/accounts';
import {
    CheckItem,
    toggled,
} from '@/modules/identity-access/components/check-item';
import { currenciesQuery } from '@/modules/master-data/currencies';
import { dimensionTypesQuery } from '@/modules/master-data/dimensions';

/** The part of the account form that holds the posting attributes (GL-FR-02). */
export type PostingValues = {
    allowed_sources: PostingSource[];
    currency_restriction: CurrencyRestriction;
    /** ISO code of the one foreign currency; empty unless the restriction is `specific`. */
    restricted_currency: string;
    is_revaluable: boolean;
    /** Dimension type key => requirement; a type that is absent is optional. */
    dimension_requirements: Record<string, DimensionRequirement>;
};

/** What the user may read, which decides which of the attributes can be offered. */
export type PostingAccess = { currencies: boolean; dimensions: boolean };

/** The shape the dialog form must have for these fields: its posting attributes live under `posting`. */
export type PostingHost = { posting: PostingValues };

type PostingErrors = Partial<Record<keyof PostingValues, { message?: string }>>;

/**
 * Sources an account takes, its currency restriction, the revaluation mark and its dimension
 * requirements. Everything is data per account, so a company shapes its own control accounts (P-01).
 */
export function AccountPostingFields({
    companyId,
    access,
    control,
    errors,
}: {
    companyId: string;
    access: PostingAccess;
    control: Control<PostingHost>;
    errors?: PostingErrors;
}) {
    const { t } = useTranslation();
    const form = control;
    // Subscribes this component itself: it must not depend on its parent re-rendering.
    const restriction = useWatch({
        control,
        name: 'posting.currency_restriction',
    });
    const currencies = useQuery({
        ...currenciesQuery(companyId),
        enabled: access.currencies,
    });
    const dimensionTypes = useQuery({
        ...dimensionTypesQuery(companyId),
        enabled: access.dimensions,
    });

    const foreign = (currencies.data ?? [])
        .filter((currency) => !currency.is_functional)
        .map((currency) => ({
            value: currency.code,
            label: `${currency.code} · ${currency.name}`,
        }));

    return (
        <FieldSet className="gap-4 rounded-lg border p-4">
            <FieldLegend variant="label" className="px-1">
                {t('accounts.posting.title')}
            </FieldLegend>

            <Controller
                control={form}
                name="posting.allowed_sources"
                render={({ field }) => (
                    <FieldSet className="gap-2">
                        <FieldLegend variant="label">
                            {t('accounts.posting.sources')}
                        </FieldLegend>
                        <FieldDescription>
                            {t('accounts.posting.sourcesHint')}
                        </FieldDescription>
                        {POSTING_SOURCES.map((source) => (
                            <CheckItem
                                key={source}
                                id={`account-source-${source}`}
                                label={t(`accounts.posting.source.${source}`)}
                                checked={field.value.includes(source)}
                                // One source must stay: an account that takes none can never be posted to.
                                disabled={
                                    field.value.length === 1 &&
                                    field.value.includes(source)
                                }
                                onChange={(on) =>
                                    field.onChange(
                                        toggled(field.value, source, on),
                                    )
                                }
                            />
                        ))}
                        {errors?.allowed_sources && (
                            <FieldError>
                                {errors?.allowed_sources.message}
                            </FieldError>
                        )}
                    </FieldSet>
                )}
            />

            <Field>
                <FieldLabel htmlFor="account-currency-restriction">
                    {t('accounts.posting.currency')}
                </FieldLabel>
                <Controller
                    control={form}
                    name="posting.currency_restriction"
                    render={({ field }) => {
                        const options = CURRENCY_RESTRICTIONS.map((value) => ({
                            value,
                            label: t(`accounts.posting.restriction.${value}`),
                        }));

                        return (
                            <Select
                                value={field.value}
                                items={options}
                                onValueChange={field.onChange}
                            >
                                <SelectTrigger
                                    id="account-currency-restriction"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {options.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        );
                    }}
                />
            </Field>

            {restriction === 'specific' && (
                <Field
                    data-invalid={
                        errors?.restricted_currency ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="account-restricted-currency">
                        {t('accounts.posting.restrictedCurrency')}
                    </FieldLabel>
                    <Controller
                        control={form}
                        name="posting.restricted_currency"
                        render={({ field }) => (
                            <SearchableSelect
                                id="account-restricted-currency"
                                items={foreign}
                                value={field.value}
                                onValueChange={field.onChange}
                                placeholder={t(
                                    'accounts.posting.chooseCurrency',
                                )}
                                emptyText={t('common.noMatch')}
                                aria-invalid={
                                    errors?.restricted_currency
                                        ? true
                                        : undefined
                                }
                            />
                        )}
                    />
                    {errors?.restricted_currency && (
                        <FieldError>
                            {errors?.restricted_currency.message}
                        </FieldError>
                    )}
                </Field>
            )}

            <Controller
                control={form}
                name="posting.is_revaluable"
                render={({ field }) => (
                    <CheckItem
                        id="account-revaluable"
                        label={t('accounts.posting.revaluable')}
                        description={t('accounts.posting.revaluableHint')}
                        checked={
                            field.value && restriction !== 'functional_only'
                        }
                        // A balance only in the functional currency has nothing to revalue.
                        disabled={restriction === 'functional_only'}
                        onChange={field.onChange}
                    />
                )}
            />

            {access.dimensions && (dimensionTypes.data ?? []).length > 0 && (
                <FieldSet className="gap-2">
                    <FieldLegend variant="label">
                        {t('accounts.posting.dimensions')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('accounts.posting.dimensionsHint')}
                    </FieldDescription>
                    <Controller
                        control={form}
                        name="posting.dimension_requirements"
                        render={({ field }) => (
                            <div className="flex flex-col gap-2">
                                {(dimensionTypes.data ?? []).map((type) => {
                                    const options = DIMENSION_REQUIREMENTS.map(
                                        (value) => ({
                                            value,
                                            label: t(
                                                `accounts.posting.requirement.${value}`,
                                            ),
                                        }),
                                    );

                                    return (
                                        <div
                                            key={type.id}
                                            className="grid items-center gap-2 sm:grid-cols-[1fr_12rem]"
                                        >
                                            <span
                                                id={`account-dimension-${type.key}`}
                                                className="text-sm"
                                            >
                                                {type.label}
                                            </span>
                                            <Select
                                                value={
                                                    field.value[type.key] ??
                                                    'optional'
                                                }
                                                items={options}
                                                onValueChange={(value) => {
                                                    const next = {
                                                        ...field.value,
                                                    };

                                                    if (value === 'optional') {
                                                        delete next[type.key];
                                                    } else {
                                                        next[type.key] =
                                                            value as DimensionRequirement;
                                                    }

                                                    field.onChange(next);
                                                }}
                                            >
                                                <SelectTrigger
                                                    className="w-full"
                                                    aria-labelledby={`account-dimension-${type.key}`}
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        {options.map(
                                                            (option) => (
                                                                <SelectItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    />
                </FieldSet>
            )}
        </FieldSet>
    );
}
