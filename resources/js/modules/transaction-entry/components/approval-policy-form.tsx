import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ArrowDownIcon,
    ArrowUpIcon,
    PlusIcon,
    Trash2Icon,
    XIcon,
} from 'lucide-react';
import { useState } from 'react';
import {
    Controller,
    useFieldArray,
    useForm,
    useWatch,
    type Control,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { actionErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import {
    approvalPolicyQuery,
    ENTRY_SOURCES,
    type EntrySource,
    stagePermissionsQuery,
    updateApprovalPolicy,
    type ApprovalPolicy,
} from '@/modules/transaction-entry/approval-policy';
import { OptionSelect } from '@/modules/transaction-entry/components/option-select';
import { transactionTypesQuery } from '@/modules/transaction-entry/transaction-types';

const ANY = '__any';

type RuleValues = {
    transaction_type_key: string;
    source: string;
    threshold: string;
    stages: string[];
    auto_post: boolean;
};

type Values = {
    separation_of_duties: boolean;
    rules: RuleValues[];
};

const blankRule = (): RuleValues => ({
    transaction_type_key: ANY,
    source: ANY,
    threshold: '',
    stages: ['entries.approve'],
    auto_post: false,
});

const valuesOf = (policy: ApprovalPolicy): Values => ({
    separation_of_duties: policy.separation_of_duties,
    rules: policy.rules.map((rule) => ({
        transaction_type_key: rule.transaction_type_key ?? ANY,
        source: rule.source ?? ANY,
        threshold: rule.threshold ?? '',
        stages: [...rule.stages],
        auto_post: rule.auto_post,
    })),
});

function Stages({
    value,
    onChange,
    permissions,
    ruleNumber,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    permissions: { value: string; label: string }[];
    ruleNumber: number;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-2">
            {value.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('approvalPolicy.noStages')}
                </p>
            ) : (
                <ol className="flex flex-col gap-2">
                    {value.map((permission, index) => (
                        <li key={index} className="flex items-center gap-2">
                            <span className="w-16 shrink-0 text-sm text-muted-foreground">
                                {t('approvalPolicy.stage', { n: index + 1 })}
                            </span>
                            <SearchableSelect
                                items={permissions}
                                value={permission}
                                onValueChange={(next) =>
                                    onChange(
                                        value.map((item, at) =>
                                            at === index ? next : item,
                                        ),
                                    )
                                }
                                emptyText={t('common.noMatch')}
                                className="w-full max-w-xs font-mono text-xs"
                                aria-label={t(
                                    'approvalPolicy.stagePermission',
                                    {
                                        n: index + 1,
                                        rule: ruleNumber,
                                    },
                                )}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={t('approvalPolicy.removeStage', {
                                    n: index + 1,
                                    rule: ruleNumber,
                                })}
                                onClick={() =>
                                    onChange(
                                        value.filter((_, at) => at !== index),
                                    )
                                }
                            >
                                <XIcon aria-hidden />
                            </Button>
                        </li>
                    ))}
                </ol>
            )}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...value, 'entries.approve'])}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('approvalPolicy.addStage')}
                </Button>
            </div>
        </div>
    );
}

/** The rule as one sentence, so the order and the effect of the rules can be read at a glance. */
function RuleSummary({
    control,
    index,
    typeLabel,
}: {
    control: Control<Values>;
    index: number;
    typeLabel: (key: string) => string;
}) {
    const { t, i18n } = useTranslation();
    const rule = useWatch({ control, name: `rules.${index}` });

    if (!rule) {
        return null;
    }

    const parts = [
        rule.transaction_type_key === ANY
            ? t('approvalPolicy.summary.anyType')
            : t('approvalPolicy.summary.type', {
                  type: typeLabel(rule.transaction_type_key),
              }),
        rule.source === ANY
            ? null
            : t('approvalPolicy.summary.source', {
                  source: t(
                      `approvalPolicy.sources.${rule.source as EntrySource}`,
                  ),
              }),
        rule.threshold === ''
            ? null
            : t('approvalPolicy.summary.threshold', {
                  amount: new Intl.NumberFormat(i18n.language).format(
                      Number(rule.threshold),
                  ),
              }),
    ].filter((part): part is string => part !== null);

    return (
        <p className="text-sm text-muted-foreground">
            {parts.join(' · ')}
            {' → '}
            {rule.stages.length === 0
                ? t('approvalPolicy.summary.auto')
                : t('approvalPolicy.summary.stages', {
                      count: rule.stages.length,
                  })}
            {rule.auto_post ? ` · ${t('approvalPolicy.summary.autoPost')}` : ''}
        </p>
    );
}

function PolicyFields({
    companyId,
    policy,
}: {
    companyId: string;
    policy: ApprovalPolicy;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [formError, setFormError] = useState<string | null>(null);
    const types = useQuery(transactionTypesQuery(companyId));
    const permissions = useQuery(stagePermissionsQuery(companyId));
    // The version being edited; it moves on when a save is accepted or a newer one is found.
    const [current, setCurrent] = useState(policy);
    const form = useForm<Values>({ defaultValues: valuesOf(policy) });
    const {
        control,
        handleSubmit,
        formState: { errors },
    } = form;
    const { fields, append, remove, move } = useFieldArray({
        control,
        name: 'rules',
    });
    const typeItems = [
        { value: ANY, label: t('approvalPolicy.anyType') },
        ...(types.data ?? []).map((type) => ({
            value: type.key,
            label: type.label,
        })),
        { value: 'system.reversal', label: t('approvalPolicy.reversal') },
    ];
    const typeLabel = (key: string) =>
        typeItems.find((item) => item.value === key)?.label ?? key;
    const sourceItems = [
        { value: ANY, label: t('approvalPolicy.anySource') },
        ...ENTRY_SOURCES.map((value) => ({
            value,
            label: t(`approvalPolicy.sources.${value}`),
        })),
    ];
    const permissionItems = (permissions.data ?? []).map((key) => ({
        value: key,
        label: key,
    }));

    const save = useMutation({
        mutationFn: (values: Values) =>
            updateApprovalPolicy(companyId, {
                lock_version: current.lock_version,
                separation_of_duties: values.separation_of_duties,
                rules: values.rules.map((rule) => ({
                    transaction_type_key:
                        rule.transaction_type_key === ANY
                            ? null
                            : rule.transaction_type_key,
                    source:
                        rule.source === ANY
                            ? null
                            : (rule.source as (typeof ENTRY_SOURCES)[number]),
                    threshold: rule.threshold === '' ? null : rule.threshold,
                    stages: rule.stages,
                    auto_post: rule.auto_post,
                })),
            }),
        onSuccess: async (saved) => {
            queryClient.setQueryData(
                approvalPolicyQuery(companyId).queryKey,
                saved,
            );
            setCurrent(saved);
            form.reset(valuesOf(saved));
            toast.success(
                t('approvalPolicy.saved', { version: saved.version }),
            );
        },
        onError: async (error) => {
            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                const latest = await queryClient.fetchQuery({
                    ...approvalPolicyQuery(companyId),
                    staleTime: 0,
                });

                setCurrent(latest);
                form.reset(valuesOf(latest));
                setFormError(t('approvalPolicy.conflict'));

                return;
            }

            if (isApiError(error) && error.status === 422) {
                // The API names the rule and the part of it: `rules.1.stages.0`. A part shows under its rule.
                let placed = false;

                for (const [key, messages] of Object.entries(error.errors)) {
                    const match = /^rules\.(\d+)\.(\w+)/.exec(key);
                    const message = messages[0];

                    if (match && message) {
                        form.setError(
                            `rules.${match[1]}.${match[2]}` as `rules.${number}.stages`,
                            { type: 'server', message },
                        );
                        placed = true;
                    }
                }

                if (placed) {
                    return;
                }
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    return (
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
                    name="separation_of_duties"
                    render={({ field }) => (
                        <Field orientation="horizontal">
                            <Checkbox
                                id="policy-sod"
                                checked={field.value}
                                onCheckedChange={(next) =>
                                    field.onChange(next === true)
                                }
                            />
                            <div className="flex flex-col gap-0.5">
                                <FieldLabel
                                    htmlFor="policy-sod"
                                    className="font-normal"
                                >
                                    {t('approvalPolicy.sod')}
                                </FieldLabel>
                                <FieldDescription>
                                    {t('approvalPolicy.sodHint')}
                                </FieldDescription>
                            </div>
                        </Field>
                    )}
                />

                <FieldSet>
                    <FieldLegend variant="label">
                        {t('approvalPolicy.rules')}
                    </FieldLegend>
                    <FieldDescription>
                        {t('approvalPolicy.rulesHint')}
                    </FieldDescription>

                    {fields.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            {t('approvalPolicy.noRules')}
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-3">
                            {fields.map((row, index) => {
                                const n = index + 1;
                                const ruleErrors = errors.rules?.[index];
                                const messages = [
                                    ruleErrors?.transaction_type_key?.message,
                                    ruleErrors?.source?.message,
                                    ruleErrors?.threshold?.message,
                                    (
                                        ruleErrors?.stages as
                                            | { message?: string }
                                            | undefined
                                    )?.message,
                                ].filter((m): m is string => Boolean(m));

                                return (
                                    <li
                                        key={row.id}
                                        className="flex flex-col gap-3 rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-sm font-medium">
                                                {t('approvalPolicy.rule', {
                                                    n,
                                                })}
                                            </span>
                                            <div className="flex">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    disabled={index === 0}
                                                    aria-label={t(
                                                        'approvalPolicy.moveUp',
                                                        { n },
                                                    )}
                                                    onClick={() =>
                                                        move(index, index - 1)
                                                    }
                                                >
                                                    <ArrowUpIcon aria-hidden />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    disabled={
                                                        index ===
                                                        fields.length - 1
                                                    }
                                                    aria-label={t(
                                                        'approvalPolicy.moveDown',
                                                        { n },
                                                    )}
                                                    onClick={() =>
                                                        move(index, index + 1)
                                                    }
                                                >
                                                    <ArrowDownIcon
                                                        aria-hidden
                                                    />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={t(
                                                        'approvalPolicy.removeRule',
                                                        { n },
                                                    )}
                                                    onClick={() =>
                                                        remove(index)
                                                    }
                                                >
                                                    <Trash2Icon aria-hidden />
                                                </Button>
                                            </div>
                                        </div>

                                        <RuleSummary
                                            control={control}
                                            index={index}
                                            typeLabel={typeLabel}
                                        />

                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <Controller
                                                control={control}
                                                name={`rules.${index}.transaction_type_key`}
                                                render={({ field }) => (
                                                    <Field>
                                                        <FieldLabel
                                                            htmlFor={`rule-type-${row.id}`}
                                                        >
                                                            {t(
                                                                'approvalPolicy.type',
                                                            )}
                                                        </FieldLabel>
                                                        <OptionSelect
                                                            id={`rule-type-${row.id}`}
                                                            value={field.value}
                                                            items={typeItems}
                                                            onValueChange={
                                                                field.onChange
                                                            }
                                                        />
                                                    </Field>
                                                )}
                                            />
                                            <Controller
                                                control={control}
                                                name={`rules.${index}.source`}
                                                render={({ field }) => (
                                                    <Field>
                                                        <FieldLabel
                                                            htmlFor={`rule-source-${row.id}`}
                                                        >
                                                            {t(
                                                                'approvalPolicy.source',
                                                            )}
                                                        </FieldLabel>
                                                        <OptionSelect
                                                            id={`rule-source-${row.id}`}
                                                            value={field.value}
                                                            items={sourceItems}
                                                            onValueChange={
                                                                field.onChange
                                                            }
                                                        />
                                                    </Field>
                                                )}
                                            />
                                            <Controller
                                                control={control}
                                                name={`rules.${index}.threshold`}
                                                render={({ field }) => (
                                                    <Field>
                                                        <FieldLabel
                                                            htmlFor={`rule-threshold-${row.id}`}
                                                        >
                                                            {t(
                                                                'approvalPolicy.threshold',
                                                            )}
                                                        </FieldLabel>
                                                        <MoneyInput
                                                            id={`rule-threshold-${row.id}`}
                                                            value={field.value}
                                                            onBlur={
                                                                field.onBlur
                                                            }
                                                            onChange={
                                                                field.onChange
                                                            }
                                                            placeholder={t(
                                                                'approvalPolicy.noThreshold',
                                                            )}
                                                        />
                                                    </Field>
                                                )}
                                            />
                                        </div>

                                        <Controller
                                            control={control}
                                            name={`rules.${index}.stages`}
                                            render={({ field }) => (
                                                <Stages
                                                    value={field.value}
                                                    onChange={field.onChange}
                                                    permissions={
                                                        permissionItems
                                                    }
                                                    ruleNumber={n}
                                                />
                                            )}
                                        />

                                        <Controller
                                            control={control}
                                            name={`rules.${index}.auto_post`}
                                            render={({ field }) => (
                                                <Field orientation="horizontal">
                                                    <Checkbox
                                                        id={`rule-post-${row.id}`}
                                                        checked={field.value}
                                                        onCheckedChange={(
                                                            next,
                                                        ) =>
                                                            field.onChange(
                                                                next === true,
                                                            )
                                                        }
                                                    />
                                                    <FieldLabel
                                                        htmlFor={`rule-post-${row.id}`}
                                                        className="font-normal"
                                                    >
                                                        {t(
                                                            'approvalPolicy.autoPost',
                                                        )}
                                                    </FieldLabel>
                                                </Field>
                                            )}
                                        />

                                        {messages.length > 0 && (
                                            <ul
                                                role="alert"
                                                className="flex flex-col gap-0.5 text-sm text-destructive"
                                            >
                                                {[...new Set(messages)].map(
                                                    (message) => (
                                                        <li key={message}>
                                                            {message}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                        </ol>
                    )}

                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => append(blankRule())}
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('approvalPolicy.addRule')}
                        </Button>
                    </div>
                    <FieldDescription>
                        {t('approvalPolicy.fallback')}
                    </FieldDescription>
                </FieldSet>

                <div>
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('common.saving')}
                    >
                        {t('approvalPolicy.save')}
                    </SubmitButton>
                </div>
            </FieldGroup>
        </form>
    );
}

/** The editor of the policy, started from the version that was read. */
export function ApprovalPolicyForm({
    companyId,
    policy,
}: {
    companyId: string;
    policy: ApprovalPolicy;
}) {
    return <PolicyFields companyId={companyId} policy={policy} />;
}
