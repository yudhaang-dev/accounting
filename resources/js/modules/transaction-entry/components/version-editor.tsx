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
import { Label } from '@/components/ui/label';
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
    type PostingRule,
    type SubledgerRoute,
    type TransactionType,
    type TypeVersion,
    type VersionInput,
    type VersionMode,
} from '@/modules/transaction-entry/transaction-types';
import { partyRolesQuery } from '@/modules/subledger/party-roles';
import { postingProfilesQuery } from '@/modules/subledger/posting-profiles';

type AccountClassKey = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

type FieldRow = {
    name: string;
    type: FieldType;
    label: string;
    required: boolean;
    account_ids: string[];
    account_classes: AccountClassKey[];
    dimension_type: string;
    allowed_roles: string[];
};

type RuleRow = {
    type: 'amount' | 'fx_difference';
    side: 'debit' | 'credit';
    amount_field: string;
    account_source: 'fixed' | 'field' | 'control_account' | 'cash_account';
    account_id: string;
    account_field: string;
    party_field: string;
    apply_dimensions: boolean;
    reuses_field: boolean;
};

/** The route as the screen holds it: a field the route does not use is empty text. */
type RouteRow = {
    kind: string;
    control_role: string;
    party_field: string;
    document_number_field: string;
    due_date_field: string;
    cash_account_field: string;
    counterpart_cash_account_field: string;
    allocations_field: string;
    /** The posting profile whose control account the route posts to; empty means the profile of the party. */
    posting_profile_id: string;
};

type Values = {
    mode: VersionMode;
    fields: FieldRow[];
    rules: RuleRow[];
    allowed_currencies: string[];
    rate_type_key: string;
    allow_rate_input: boolean;
    subledger_route: RouteRow | null;
};

/** The kinds a route can record; `transfer` and `cash` move cash and are no document (TE-FR-10, ADR-0016). */
export const ROUTE_KINDS = [
    'invoice',
    'credit_note',
    'payment',
    'advance',
    'write_off',
    'loan',
    'transfer',
    'cash',
] as const;

const ROUTE_ROLES = ['receivables_control', 'payables_control'] as const;

/** What a kind of document does, which decides the fields of the route it can use (SL-FR-04, SL-FR-11). */
const MOVES_CASH = ['payment', 'advance', 'loan', 'cash'];
const ALLOCATES = ['credit_note', 'payment', 'write_off'];

const blankRoute = (): RouteRow => ({
    kind: 'invoice',
    control_role: 'receivables_control',
    party_field: '',
    document_number_field: '',
    due_date_field: '',
    cash_account_field: '',
    counterpart_cash_account_field: '',
    allocations_field: '',
    posting_profile_id: '',
});

const NAME_PATTERN = /^[a-z][a-z0-9_]*$/;

/** The message of a field error that may sit on the list itself or on one of its items. */
function errorMessage(error: unknown): string | undefined {
    if (error === null || typeof error !== 'object') {
        return undefined;
    }

    const own = (error as { message?: unknown }).message;

    if (typeof own === 'string') {
        return own;
    }

    for (const item of Object.values(error)) {
        const nested = errorMessage(item);

        if (nested !== undefined) {
            return nested;
        }
    }

    return undefined;
}

const blankField = (): FieldRow => ({
    name: '',
    type: 'text',
    label: '',
    required: true,
    account_ids: [],
    account_classes: [],
    dimension_type: '',
    allowed_roles: [],
});

const blankRule = (): RuleRow => ({
    type: 'amount',
    side: 'debit',
    amount_field: '',
    account_source: 'field',
    account_id: '',
    account_field: '',
    party_field: '',
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
            account_classes: (field.account_classes ?? []) as AccountClassKey[],
            dimension_type: field.dimension_type ?? '',
            allowed_roles: field.allowed_roles ?? [],
        })),
        rules: (version.posting_rules ?? []).map((rule) => ({
            type: rule.type,
            side: rule.side ?? 'debit',
            amount_field: rule.amount_field ?? '',
            account_source: rule.account?.source ?? 'field',
            account_id: rule.account?.account_id ?? '',
            account_field: rule.account?.field ?? '',
            party_field: rule.account?.party_field ?? '',
            apply_dimensions: rule.apply_dimensions ?? false,
            reuses_field: rule.reuses_field ?? false,
        })),
        allowed_currencies: version.allowed_currencies ?? [],
        rate_type_key: version.rate_type_key ?? '',
        allow_rate_input: version.allow_rate_input,
        subledger_route: version.subledger_route
            ? {
                  kind: version.subledger_route.kind ?? 'invoice',
                  control_role: version.subledger_route.control_role ?? '',
                  party_field: version.subledger_route.party_field ?? '',
                  document_number_field:
                      version.subledger_route.document_number_field ?? '',
                  due_date_field: version.subledger_route.due_date_field ?? '',
                  cash_account_field:
                      version.subledger_route.cash_account_field ?? '',
                  counterpart_cash_account_field:
                      version.subledger_route.counterpart_cash_account_field ??
                      '',
                  allocations_field:
                      version.subledger_route.allocations_field ?? '',
                  posting_profile_id:
                      version.subledger_route.posting_profile_id ?? '',
              }
            : null,
    };
}

/**
 * The route as the API takes it. A field the kind cannot use is left out, so changing the kind never
 * leaves behind a field the server would refuse.
 */
function routeInputOf(route: RouteRow): SubledgerRoute {
    const transfer = route.kind === 'transfer';
    const noParty = transfer || route.kind === 'cash';
    const optional = (value: string, used: boolean) =>
        used && value !== '' ? value : null;

    return {
        kind: route.kind,
        control_role: noParty ? null : route.control_role,
        party_field: noParty ? null : route.party_field,
        document_number_field: optional(route.document_number_field, !transfer),
        due_date_field: optional(route.due_date_field, !noParty),
        cash_account_field: optional(
            route.cash_account_field,
            transfer || MOVES_CASH.includes(route.kind),
        ),
        counterpart_cash_account_field: optional(
            route.counterpart_cash_account_field,
            transfer,
        ),
        allocations_field: optional(
            route.allocations_field,
            ALLOCATES.includes(route.kind),
        ),
        posting_profile_id: optional(route.posting_profile_id, !noParty),
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
                  ...(field.type === 'account' &&
                  field.account_classes.length > 0
                      ? { account_classes: field.account_classes }
                      : {}),
                  ...(field.type === 'dimension' && field.dimension_type
                      ? { dimension_type: field.dimension_type }
                      : {}),
                  ...(field.type === 'party' && field.allowed_roles.length > 0
                      ? { allowed_roles: field.allowed_roles }
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
                            account: accountOfRule(rule),
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
        // A free-lines version has no route; for a structured one, null takes the route off the draft.
        ...(structured
            ? {
                  // The API takes null here (nullable); the generated type leaves it out.
                  subledger_route:
                      values.subledger_route === null
                          ? (null as unknown as SubledgerRoute)
                          : routeInputOf(values.subledger_route),
              }
            : {}),
    };
}

/** The account of a rule as the API takes it. */
function accountOfRule(rule: RuleRow): NonNullable<PostingRule['account']> {
    switch (rule.account_source) {
        case 'fixed':
            return { source: 'fixed', account_id: rule.account_id };
        case 'control_account':
            return { source: 'control_account', party_field: rule.party_field };
        case 'cash_account':
            return { source: 'cash_account', field: rule.account_field };
        default:
            return { source: 'field', field: rule.account_field };
    }
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
                      : rule[2] === 'account.party_field'
                        ? 'party_field'
                        : rule[2]?.startsWith('account')
                          ? 'account_id'
                          : rule[2]
              }`
            : key.startsWith('allowed_currencies')
              ? 'allowed_currencies'
              : /^fields\.\d+\./.test(key) || key.startsWith('subledger_route')
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

/** The roles a party field accepts, picked from the company's roles; none checked means any party (TE-FR-04). */
const ACCOUNT_CLASSES: AccountClassKey[] = [
    'asset',
    'liability',
    'equity',
    'income',
    'expense',
];

/** The classes of account an account field accepts; none ticked means every class (TE-FR-04). */
function ClassSet({
    value,
    onChange,
    scope,
}: {
    value: AccountClassKey[];
    onChange: (value: AccountClassKey[]) => void;
    scope: number;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap gap-x-5 gap-y-2">
            {ACCOUNT_CLASSES.map((accountClass) => (
                <div key={accountClass} className="flex items-center gap-2">
                    <Checkbox
                        id={`allowed-class-${scope}-${accountClass}`}
                        checked={value.includes(accountClass)}
                        onCheckedChange={(checked) =>
                            onChange(
                                checked === true
                                    ? [...value, accountClass]
                                    : value.filter(
                                          (item) => item !== accountClass,
                                      ),
                            )
                        }
                    />
                    <Label
                        htmlFor={`allowed-class-${scope}-${accountClass}`}
                        className="font-normal"
                    >
                        {t(`accountClass.${accountClass}`)}
                    </Label>
                </div>
            ))}
        </div>
    );
}

function RoleSet({
    value,
    onChange,
    roles,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    roles: { key: string; label: string; status: string }[];
}) {
    const { t } = useTranslation();
    // A role the field still names stays visible when it was deactivated, so the publish error is understandable.
    const shown = roles.filter(
        (role) => role.status === 'active' || value.includes(role.key),
    );
    const unknown = value.filter(
        (key) => !roles.some((role) => role.key === key),
    );

    return (
        <div className="flex flex-wrap gap-x-5 gap-y-2">
            {[
                ...shown,
                ...unknown.map((key) => ({
                    key,
                    label: key,
                    status: 'inactive',
                })),
            ].map((role) => (
                <div key={role.key} className="flex items-center gap-2">
                    <Checkbox
                        id={`allowed-role-${role.key}-${shown.length}`}
                        checked={value.includes(role.key)}
                        onCheckedChange={(checked) =>
                            onChange(
                                checked === true
                                    ? [...value, role.key]
                                    : value.filter((key) => key !== role.key),
                            )
                        }
                    />
                    <Label
                        htmlFor={`allowed-role-${role.key}-${shown.length}`}
                        className="font-normal"
                    >
                        {role.label}
                        {role.status === 'active'
                            ? ''
                            : ` (${t('transactionTypes.designer.roleInactive')})`}
                    </Label>
                </div>
            ))}
            {shown.length === 0 && unknown.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('transactionTypes.designer.noPartyRoles')}
                </p>
            )}
        </div>
    );
}

function FieldRows({
    accounts,
    dimensionTypes,
    partyRoles,
}: {
    accounts: OptionSelectItem[];
    dimensionTypes: OptionSelectItem[];
    partyRoles: { key: string; label: string; status: string }[];
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
    }));

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
                            {kind === 'account' && (
                                <Field>
                                    <FieldLabel className="text-xs text-muted-foreground">
                                        {t(
                                            'transactionTypes.designer.allowedClasses',
                                        )}
                                    </FieldLabel>
                                    <Controller
                                        control={control}
                                        name={`fields.${index}.account_classes`}
                                        render={({ field }) => (
                                            <ClassSet
                                                value={field.value}
                                                onChange={field.onChange}
                                                scope={index}
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
                            {kind === 'party' && (
                                <Field>
                                    <FieldLabel className="text-xs text-muted-foreground">
                                        {t(
                                            'transactionTypes.designer.allowedRoles',
                                        )}
                                    </FieldLabel>
                                    <Controller
                                        control={control}
                                        name={`fields.${index}.allowed_roles`}
                                        render={({ field }) => (
                                            <RoleSet
                                                value={field.value}
                                                onChange={field.onChange}
                                                roles={partyRoles}
                                            />
                                        )}
                                    />
                                </Field>
                            )}
                            {[
                                rowErrors?.name,
                                rowErrors?.label,
                                rowErrors?.account_ids,
                                rowErrors?.allowed_roles,
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
                                        errorMessage(rowErrors?.allowed_roles),
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

/**
 * The subledger route of a structured version (TE-FR-10): the entry records a document of one kind for
 * the party in one of its fields, and the posting rules may then use the control account of that party
 * and the account of a cash account field. A `transfer` moves cash between two cash account fields.
 */
function RouteEditor({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const profiles = useQuery(postingProfilesQuery(companyId));
    const {
        control,
        setValue,
        formState: { errors },
    } = useFormContext<Values>();
    const route = useWatch({ control, name: 'subledger_route' });
    const defined = useWatch({ control, name: 'fields' });
    const routeErrors = errors.subledger_route;
    const fieldsOfType = (type: FieldType) =>
        defined
            .filter((field) => field.type === type && field.name !== '')
            .map((field) => ({
                value: field.name,
                label: field.label || field.name,
            }));
    const kindItems = ROUTE_KINDS.map((value) => ({
        value,
        label: t(`transactionTypes.designer.route.kinds.${value}`),
    }));
    const roleItems = ROUTE_ROLES.map((value) => ({
        value,
        label: t(`transactionTypes.designer.route.roles.${value}`),
    }));
    const transfer = route?.kind === 'transfer';
    const noParty = transfer || route?.kind === 'cash';
    const picker = (
        key: Exclude<
            keyof RouteRow,
            'kind' | 'control_role' | 'posting_profile_id'
        >,
        type: FieldType,
        required: boolean,
    ) => (
        <Controller
            control={control}
            name={`subledger_route.${key}`}
            rules={
                required
                    ? {
                          validate: (value) =>
                              (value ?? '') !== '' || t('common.required'),
                      }
                    : undefined
            }
            render={({ field }) => (
                <Field data-invalid={routeErrors?.[key] ? true : undefined}>
                    <FieldLabel className="text-xs text-muted-foreground">
                        {t(`transactionTypes.designer.route.fields.${key}`)}
                    </FieldLabel>
                    <OptionSelect
                        value={field.value ?? ''}
                        items={[
                            ...(required
                                ? []
                                : [
                                      {
                                          value: '__none',
                                          label: t(
                                              'transactionTypes.designer.route.none',
                                          ),
                                      },
                                  ]),
                            ...fieldsOfType(type),
                        ]}
                        placeholder={t(
                            'transactionTypes.designer.route.chooseField',
                        )}
                        onValueChange={(next) =>
                            field.onChange(next === '__none' ? '' : next)
                        }
                        aria-label={t(
                            `transactionTypes.designer.route.fields.${key}`,
                        )}
                        aria-invalid={routeErrors?.[key] ? true : undefined}
                    />
                    {routeErrors?.[key]?.message && (
                        <FieldError>{routeErrors[key]?.message}</FieldError>
                    )}
                </Field>
            )}
        />
    );

    return (
        <FieldSet>
            <FieldLegend variant="label">
                {t('transactionTypes.designer.route.title')}
            </FieldLegend>
            <FieldDescription>
                {t('transactionTypes.designer.route.hint')}
            </FieldDescription>
            <Field orientation="horizontal">
                <Checkbox
                    id="version-route"
                    checked={route !== null}
                    onCheckedChange={(next) =>
                        setValue(
                            'subledger_route',
                            next === true ? blankRoute() : null,
                        )
                    }
                />
                <FieldLabel htmlFor="version-route" className="font-normal">
                    {t('transactionTypes.designer.route.enable')}
                </FieldLabel>
            </Field>
            {route !== null && (
                <div className="flex flex-col gap-3 rounded-lg border p-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Controller
                            control={control}
                            name="subledger_route.kind"
                            render={({ field }) => (
                                <Field>
                                    <FieldLabel className="text-xs text-muted-foreground">
                                        {t(
                                            'transactionTypes.designer.route.kind',
                                        )}
                                    </FieldLabel>
                                    <OptionSelect
                                        value={field.value ?? 'invoice'}
                                        items={kindItems}
                                        onValueChange={field.onChange}
                                        aria-label={t(
                                            'transactionTypes.designer.route.kind',
                                        )}
                                    />
                                </Field>
                            )}
                        />
                        {!noParty && (
                            <Controller
                                control={control}
                                name="subledger_route.control_role"
                                render={({ field }) => (
                                    <Field>
                                        <FieldLabel className="text-xs text-muted-foreground">
                                            {t(
                                                'transactionTypes.designer.route.role',
                                            )}
                                        </FieldLabel>
                                        <OptionSelect
                                            value={field.value ?? ''}
                                            items={roleItems}
                                            onValueChange={field.onChange}
                                            aria-label={t(
                                                'transactionTypes.designer.route.role',
                                            )}
                                        />
                                    </Field>
                                )}
                            />
                        )}
                        {!noParty && (
                            <Controller
                                control={control}
                                name="subledger_route.posting_profile_id"
                                render={({ field }) => (
                                    <Field
                                        data-invalid={
                                            routeErrors?.posting_profile_id
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel className="text-xs text-muted-foreground">
                                            {t(
                                                'transactionTypes.designer.route.postingProfile',
                                            )}
                                        </FieldLabel>
                                        <OptionSelect
                                            value={field.value ?? ''}
                                            items={[
                                                {
                                                    value: '__none',
                                                    label: t(
                                                        'transactionTypes.designer.route.partyProfile',
                                                    ),
                                                },
                                                ...(profiles.data ?? []).map(
                                                    (profile) => ({
                                                        value: profile.id,
                                                        label: profile.name,
                                                    }),
                                                ),
                                            ]}
                                            placeholder={t(
                                                'transactionTypes.designer.route.partyProfile',
                                            )}
                                            onValueChange={(next) =>
                                                field.onChange(
                                                    next === '__none'
                                                        ? ''
                                                        : next,
                                                )
                                            }
                                            aria-label={t(
                                                'transactionTypes.designer.route.postingProfile',
                                            )}
                                            aria-invalid={
                                                routeErrors?.posting_profile_id
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldDescription>
                                            {t(
                                                'transactionTypes.designer.route.postingProfileHint',
                                            )}
                                        </FieldDescription>
                                        {routeErrors?.posting_profile_id
                                            ?.message && (
                                            <FieldError>
                                                {
                                                    routeErrors
                                                        .posting_profile_id
                                                        .message
                                                }
                                            </FieldError>
                                        )}
                                    </Field>
                                )}
                            />
                        )}
                        {!noParty && picker('party_field', 'party', true)}
                        {!transfer &&
                            picker('document_number_field', 'reference', false)}
                        {!noParty && picker('due_date_field', 'date', false)}
                        {(transfer || MOVES_CASH.includes(route.kind)) &&
                            picker('cash_account_field', 'cash_account', true)}
                        {transfer &&
                            picker(
                                'counterpart_cash_account_field',
                                'cash_account',
                                true,
                            )}
                        {ALLOCATES.includes(route.kind) &&
                            picker(
                                'allocations_field',
                                'allocations',
                                route.kind === 'write_off',
                            )}
                    </div>
                    {transfer && (
                        <p className="text-sm text-muted-foreground">
                            {t('transactionTypes.designer.route.transferHint')}
                        </p>
                    )}
                    {route.kind === 'cash' && (
                        <p className="text-sm text-muted-foreground">
                            {t('transactionTypes.designer.route.cashHint')}
                        </p>
                    )}
                    {route.kind === 'loan' && (
                        <p className="text-sm text-muted-foreground">
                            {t('transactionTypes.designer.route.loanHint')}
                        </p>
                    )}
                    {routeErrors?.message && (
                        <ul role="alert" className="text-sm text-destructive">
                            <li>{routeErrors.message}</li>
                        </ul>
                    )}
                </div>
            )}
        </FieldSet>
    );
}

function RuleRows({ accounts }: { accounts: OptionSelectItem[] }) {
    const { t } = useTranslation();
    const {
        control,
        setValue,
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
    const fieldsOfType = (type: FieldType) =>
        defined
            .filter((field) => field.type === type && field.name !== '')
            .map((field) => ({
                value: field.name,
                label: field.label || field.name,
            }));
    const accountFields = fieldsOfType('account');
    const cashFields = fieldsOfType('cash_account');
    const partyFields = fieldsOfType('party');
    const hasRoute = useWatch({ control, name: 'subledger_route' }) !== null;
    const sideItems = (['debit', 'credit'] as const).map((value) => ({
        value,
        label: t(`transactionTypes.side.${value}`),
    }));
    const kindItems = (['amount', 'fx_difference'] as const).map((value) => ({
        value,
        label: t(`transactionTypes.designer.ruleTypes.${value}`),
    }));
    // The control and cash accounts are those of the subledger document, so they need the route.
    const sourceItems = (
        hasRoute
            ? (['field', 'fixed', 'control_account', 'cash_account'] as const)
            : (['field', 'fixed'] as const)
    ).map((value) => ({
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
                        rowErrors?.party_field?.message,
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
                                                    onValueChange={(next) => {
                                                        field.onChange(next);
                                                        // The field of one source is no choice of another.
                                                        setValue(
                                                            `rules.${index}.account_field`,
                                                            '',
                                                        );
                                                        setValue(
                                                            `rules.${index}.party_field`,
                                                            '',
                                                        );
                                                    }}
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
                                ) : rule?.account_source ===
                                  'control_account' ? (
                                    <Controller
                                        control={control}
                                        name={`rules.${index}.party_field`}
                                        rules={{
                                            validate: (value) =>
                                                value !== '' ||
                                                t(
                                                    'transactionTypes.designer.partyFieldRequired',
                                                ),
                                        }}
                                        render={({ field }) => (
                                            <OptionSelect
                                                value={field.value}
                                                items={partyFields}
                                                placeholder={t(
                                                    'transactionTypes.designer.choosePartyField',
                                                )}
                                                onValueChange={field.onChange}
                                                className="w-full sm:w-80"
                                                aria-label={`${t('transactionTypes.designer.partyField')} ${t('transactionTypes.designer.rule', { n })}`}
                                                aria-invalid={
                                                    rowErrors?.party_field
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
                                                    rule?.account_source ===
                                                        'cash_account'
                                                        ? 'transactionTypes.designer.cashFieldRequired'
                                                        : 'transactionTypes.designer.accountFieldRequired',
                                                ),
                                        }}
                                        render={({ field }) => (
                                            <OptionSelect
                                                value={field.value}
                                                items={
                                                    rule?.account_source ===
                                                    'cash_account'
                                                        ? cashFields
                                                        : accountFields
                                                }
                                                placeholder={t(
                                                    rule?.account_source ===
                                                        'cash_account'
                                                        ? 'transactionTypes.designer.chooseCashField'
                                                        : 'transactionTypes.designer.chooseAccountField',
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
    const partyRoles = useQuery(partyRolesQuery(companyId));
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
                                partyRoles={partyRoles.data ?? []}
                                dimensionTypes={(dimensionTypes.data ?? []).map(
                                    (dimension) => ({
                                        value: dimension.key,
                                        label: dimension.label,
                                    }),
                                )}
                            />
                            <RouteEditor companyId={companyId} />
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
