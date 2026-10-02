import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
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
import { actionErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    ledgerPolicyQuery,
    saveLedgerPolicy,
    SYSTEM_POSTING_KEYS,
    type LedgerPolicy,
} from '@/modules/general-ledger/ledger-settings';
import {
    CheckItem,
    toggled,
} from '@/modules/identity-access/components/check-item';
import { permissionsQuery } from '@/modules/identity-access/access';
import { rateTypesQuery } from '@/modules/master-data/exchange-rates';
import { transactionTypesQuery } from '@/modules/transaction-entry/entries';

type Values = {
    soft_closed_transaction_type_keys: string[];
    /** Empty means the key alone is enough. */
    soft_closed_permission: string;
    /** Empty means no override is allowed. */
    source_override_permission: string;
    fx_rounding_tolerance: string;
    /** Empty means no revaluation rate type. */
    revaluation_rate_type_key: string;
    revaluation_auto_reverse: boolean;
};

const TOLERANCE = /^\d+(\.\d{1,6})?$/;

/** Every array element error belongs to the list it is in. */
const fieldFor = (apiField: string): keyof Values | undefined => {
    const name = apiField.split('.')[0];

    return name !== undefined && name in EMPTY
        ? (name as keyof Values)
        : undefined;
};

const EMPTY: Values = {
    soft_closed_transaction_type_keys: [],
    soft_closed_permission: '',
    source_override_permission: '',
    fx_rounding_tolerance: '0',
    revaluation_rate_type_key: '',
    revaluation_auto_reverse: false,
};

/**
 * The ledger policy (GL-FR-04, GL-FR-05, GL-FR-07, GL-FR-09): what may still post into a soft-closed
 * period, who may post to a control account, the largest conversion rounding difference, and how a
 * revaluation is valued and reversed. Every save is a new version.
 */
export function LedgerPolicyForm({
    companyId,
    policy,
}: {
    companyId: string;
    policy: LedgerPolicy;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: {
            soft_closed_transaction_type_keys:
                policy.soft_closed_transaction_type_keys,
            soft_closed_permission: policy.soft_closed_permission ?? '',
            source_override_permission: policy.source_override_permission ?? '',
            fx_rounding_tolerance: policy.fx_rounding_tolerance,
            revaluation_rate_type_key: policy.revaluation_rate_type_key ?? '',
            revaluation_auto_reverse: policy.revaluation_auto_reverse,
        },
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;

    const transactionTypes = useQuery(transactionTypesQuery(companyId));
    const permissions = useQuery(permissionsQuery(companyId));
    const rateTypes = useQuery(rateTypesQuery(companyId));

    // What the system posts, the company's transaction types, and any key the policy already holds.
    const keys = [
        ...new Set([
            ...SYSTEM_POSTING_KEYS,
            ...(transactionTypes.data ?? []).map((type) => type.key),
            ...policy.soft_closed_transaction_type_keys,
        ]),
    ];
    const permissionItems = [
        ...new Set([
            ...(permissions.data ?? []).map((permission) => permission.key),
            policy.soft_closed_permission ?? '',
            policy.source_override_permission ?? '',
        ]),
    ]
        .filter(Boolean)
        .map((key) => ({ value: key, label: key }));
    const rateTypeItems = (rateTypes.data ?? []).map((type) => ({
        value: type.key,
        label: `${type.key} · ${type.label}`,
    }));

    const refresh = () =>
        queryClient.invalidateQueries({
            queryKey: ['companies', companyId, 'ledger-policy'],
        });

    const save = useMutation({
        mutationFn: (values: Values) =>
            saveLedgerPolicy(companyId, {
                lock_version: policy.lock_version,
                soft_closed_transaction_type_keys:
                    values.soft_closed_transaction_type_keys,
                soft_closed_permission: values.soft_closed_permission || null,
                source_override_permission:
                    values.source_override_permission || null,
                fx_rounding_tolerance: Number(values.fx_rounding_tolerance),
                revaluation_rate_type_key:
                    values.revaluation_rate_type_key || null,
                revaluation_auto_reverse: values.revaluation_auto_reverse,
            }),
        onSuccess: async (saved) => {
            await refresh();
            toast.success(
                t('ledgerSettings.policy.saved', { version: saved.version }),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                // The reload replaces the form with the newer version, so the notice cannot live inside it.
                toast.error(t('ledgerSettings.policy.conflict'));
                await refresh();

                return;
            }

            if (isApiError(error) && error.status === 422) {
                const fieldMap = Object.fromEntries(
                    Object.keys(error.errors).flatMap((apiField) => {
                        const field = fieldFor(apiField);

                        return field ? [[apiField, field]] : [];
                    }),
                );

                if (applyApiErrors(form.setError, error, fieldMap)) {
                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
        <form
            noValidate
            className="max-w-3xl"
            onSubmit={form.handleSubmit((values) => {
                setFormError(null);
                save.mutate(values);
            })}
        >
            <FieldGroup>
                {formError && <FormAlert>{formError}</FormAlert>}

                <FieldSet
                    data-invalid={
                        errors.soft_closed_transaction_type_keys
                            ? true
                            : undefined
                    }
                >
                    <FieldLegend variant="label">
                        {t('ledgerSettings.policy.softClosedKeys')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('ledgerSettings.policy.softClosedKeysHint')}
                    </FieldDescription>
                    <Controller
                        control={control}
                        name="soft_closed_transaction_type_keys"
                        render={({ field }) => (
                            <div className="flex flex-col gap-2">
                                {keys.map((key) => (
                                    <CheckItem
                                        key={key}
                                        id={`policy-key-${key}`}
                                        label={key}
                                        checked={field.value.includes(key)}
                                        onChange={(on) =>
                                            field.onChange(
                                                toggled(field.value, key, on),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    />
                    {errors.soft_closed_transaction_type_keys && (
                        <FieldError>
                            {errors.soft_closed_transaction_type_keys.message}
                        </FieldError>
                    )}
                </FieldSet>

                <Field
                    data-invalid={
                        errors.soft_closed_permission ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="policy-soft-permission">
                        {t('ledgerSettings.policy.softClosedPermission')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="soft_closed_permission"
                        render={({ field }) => (
                            <SearchableSelect
                                id="policy-soft-permission"
                                items={permissionItems}
                                value={field.value}
                                onValueChange={field.onChange}
                                placeholder={t('ledgerSettings.policy.none')}
                                emptyText={t('common.noMatch')}
                                clearable
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('ledgerSettings.policy.softClosedPermissionHint')}
                    </FieldDescription>
                    {errors.soft_closed_permission && (
                        <FieldError>
                            {errors.soft_closed_permission.message}
                        </FieldError>
                    )}
                </Field>

                <Field
                    data-invalid={
                        errors.source_override_permission ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="policy-override-permission">
                        {t('ledgerSettings.policy.overridePermission')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="source_override_permission"
                        render={({ field }) => (
                            <SearchableSelect
                                id="policy-override-permission"
                                items={permissionItems}
                                value={field.value}
                                onValueChange={field.onChange}
                                placeholder={t('ledgerSettings.policy.none')}
                                emptyText={t('common.noMatch')}
                                clearable
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('ledgerSettings.policy.overridePermissionHint')}
                    </FieldDescription>
                    {errors.source_override_permission && (
                        <FieldError>
                            {errors.source_override_permission.message}
                        </FieldError>
                    )}
                </Field>

                <Field
                    data-invalid={
                        errors.fx_rounding_tolerance ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="policy-rounding">
                        {t('ledgerSettings.policy.rounding')}
                    </FieldLabel>
                    <Input
                        id="policy-rounding"
                        inputMode="decimal"
                        className="w-40 tabular-nums"
                        aria-invalid={
                            errors.fx_rounding_tolerance ? true : undefined
                        }
                        {...register('fx_rounding_tolerance', {
                            required: t('common.required'),
                            validate: (value) =>
                                TOLERANCE.test(value) ||
                                t('ledgerSettings.policy.roundingInvalid'),
                        })}
                    />
                    <FieldDescription>
                        {t('ledgerSettings.policy.roundingHint')}
                    </FieldDescription>
                    {errors.fx_rounding_tolerance && (
                        <FieldError>
                            {errors.fx_rounding_tolerance.message}
                        </FieldError>
                    )}
                </Field>

                <Field
                    data-invalid={
                        errors.revaluation_rate_type_key ? true : undefined
                    }
                >
                    <FieldLabel htmlFor="policy-revaluation-rate">
                        {t('ledgerSettings.policy.revaluationRate')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="revaluation_rate_type_key"
                        render={({ field }) => (
                            <SearchableSelect
                                id="policy-revaluation-rate"
                                items={rateTypeItems}
                                value={field.value}
                                onValueChange={field.onChange}
                                placeholder={t('ledgerSettings.policy.none')}
                                emptyText={t('common.noMatch')}
                                clearable
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('ledgerSettings.policy.revaluationRateHint')}
                    </FieldDescription>
                    {errors.revaluation_rate_type_key && (
                        <FieldError>
                            {errors.revaluation_rate_type_key.message}
                        </FieldError>
                    )}
                </Field>

                <Controller
                    control={control}
                    name="revaluation_auto_reverse"
                    render={({ field }) => (
                        <CheckItem
                            id="policy-auto-reverse"
                            label={t('ledgerSettings.policy.autoReverse')}
                            description={t(
                                'ledgerSettings.policy.autoReverseHint',
                            )}
                            checked={field.value}
                            onChange={field.onChange}
                        />
                    )}
                />

                <div className="flex items-center gap-4">
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('common.save')}
                    </SubmitButton>
                    <span className="text-sm text-muted-foreground">
                        {policy.version === 0
                            ? t('ledgerSettings.policy.noVersion')
                            : t('ledgerSettings.policy.version', {
                                  version: policy.version,
                              })}
                    </span>
                </div>
            </FieldGroup>
        </form>
    );
}

/** Loads the ledger policy and shows its form; the form is rebuilt for each new version. */
export function LedgerPolicySection({ companyId }: { companyId: string }) {
    const { t } = useTranslation();
    const policy = useQuery(ledgerPolicyQuery(companyId));

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <h2 className="text-base font-semibold">
                    {t('ledgerSettings.policy.title')}
                </h2>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t('ledgerSettings.policy.description')}
                </p>
            </div>

            {policy.isError ? (
                <FormAlert>{t('common.unexpected')}</FormAlert>
            ) : policy.data === undefined ? (
                <div
                    role="status"
                    aria-label={t('common.loading')}
                    className="h-64 w-full max-w-3xl animate-pulse rounded-lg bg-muted"
                />
            ) : (
                <LedgerPolicyForm
                    key={policy.data.version}
                    companyId={companyId}
                    policy={policy.data}
                />
            )}
        </section>
    );
}
