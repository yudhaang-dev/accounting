import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { ArrowDownIcon, ArrowUpIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';
import {
    Controller,
    useFieldArray,
    useForm,
    useWatch,
    type Control,
    type FieldValues,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { SearchableSelect } from '@/components/searchable-select';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { actionErrorMessage, genericErrorMessage } from '@/lib/errors';
import { isApiError } from '@/lib/http';
import { allAccountsQuery } from '@/modules/general-ledger/accounts';
import {
    BASES,
    COLUMN_KINDS,
    KINDS,
    ROW_TYPES,
    UNMAPPED,
    blankColumn,
    blankDefinition,
    blankRow,
    createDefinition,
    formErrors,
    toForm,
    updateDefinition,
    type DefinitionForm,
} from '@/modules/reporting/definition-form';
import {
    reportDefinitionQuery,
    type ReportDefinition,
} from '@/modules/reporting/report-definitions';
import { SelectorList } from '@/modules/reporting/components/selector-list';
import { OptionSelect } from '@/modules/transaction-entry/components/option-select';
import { versionsQuery } from '@/modules/planning/plans';

type Errors = Record<string, string>;

/** Loads the definition to change, or opens an empty form for a new one. */
export function DefinitionEditorPage({
    companyId,
    definitionId,
}: {
    companyId: string;
    definitionId?: string;
}) {
    const { t } = useTranslation();
    const definition = useQuery({
        ...reportDefinitionQuery(companyId, definitionId ?? ''),
        enabled: definitionId !== undefined,
    });

    useDocumentTitle(
        definitionId
            ? t('definitionEditor.editTitle')
            : t('definitionEditor.newTitle'),
    );

    if (definitionId === undefined) {
        return <Editor companyId={companyId} />;
    }

    if (definition.isError) {
        return (
            <FormAlert>{genericErrorMessage(definition.error, t)}</FormAlert>
        );
    }

    if (definition.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    return <Editor companyId={companyId} definition={definition.data} />;
}

const options = <V extends string>(
    values: readonly V[],
    label: (value: V) => string,
) => values.map((value) => ({ value: value as string, label: label(value) }));

function Editor({
    companyId,
    definition,
}: {
    companyId: string;
    definition?: ReportDefinition;
}) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [errors, setErrors] = useState<Errors>({});
    const [formError, setFormError] = useState<string | null>(null);
    const { control, register, handleSubmit } = useForm<DefinitionForm>({
        defaultValues: definition
            ? toForm(definition)
            : {
                  ...blankDefinition(),
                  columns: [
                      {
                          ...blankColumn(),
                          key: 'period',
                          label: t('definitionEditor.defaultColumn'),
                      },
                  ],
              },
    });
    const rows = useFieldArray({ control, name: 'rows' });
    const columns = useFieldArray({ control, name: 'columns' });
    const watchedColumns = useWatch({ control, name: 'columns' });
    const accounts = useQuery(allAccountsQuery(companyId));
    const versions = useQuery({
        ...versionsQuery(companyId),
        enabled: watchedColumns.some((column) => column.kind === 'plan'),
    });
    const editing = definition !== undefined;

    const save = useMutation({
        mutationFn: (form: DefinitionForm) =>
            definition
                ? updateDefinition(companyId, definition, form)
                : createDefinition(companyId, form),
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'report-definitions'],
            });
            toast.success(t('definitionEditor.saved'));
            await navigate({
                to: '/w/$companyId/reports/$definitionId',
                params: { companyId, definitionId: saved.id },
            });
        },
        onError: async (error) => {
            if (isApiError(error) && error.status === 422) {
                setErrors(formErrors(error.errors));
                setFormError(t('definitionEditor.invalid'));

                return;
            }

            if (isApiError(error) && error.code === 'VERSION_CONFLICT') {
                await queryClient.invalidateQueries({
                    queryKey: ['companies', companyId, 'report-definitions'],
                });
                setFormError(t('definitionEditor.conflict'));

                return;
            }

            setFormError(actionErrorMessage(error, t));
        },
    });

    const submit = handleSubmit((form) => {
        setErrors({});
        setFormError(null);
        save.mutate(form);
    });

    const accountItems = (accounts.data ?? []).map((account) => ({
        value: account.id,
        label: `${account.code} · ${account.name}`,
    }));
    const err = (path: string) =>
        errors[path] ? <FieldError>{errors[path]}</FieldError> : null;
    // Messages for paths the form has no field for (the number of rows, for one) still need to be read.
    const orphans = Object.entries(errors).filter(
        ([path]) =>
            !/\.(key|label|type|sign|favorable|basis|accounts|terms|kind|shift|span|plan_version_id|minuend|subtrahend)(\.|$)/.test(
                path,
            ),
    );

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex max-w-4xl flex-col gap-6"
        >
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {editing
                        ? t('definitionEditor.editTitle')
                        : t('definitionEditor.newTitle')}
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {editing
                        ? t('definitionEditor.editDescription', {
                              version: definition.current_version,
                          })
                        : t('definitionEditor.newDescription')}
                </p>
            </div>

            {formError && <FormAlert>{formError}</FormAlert>}
            {orphans.length > 0 && (
                <FormAlert>
                    {orphans.map(([, message]) => message).join(' ')}
                </FormAlert>
            )}

            <section className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={errors.key ? true : undefined}>
                    <FieldLabel htmlFor="def-key">
                        {t('definitionEditor.key')}
                    </FieldLabel>
                    <Input
                        id="def-key"
                        disabled={editing}
                        {...register('key')}
                    />
                    {err('key')}
                </Field>
                <Field data-invalid={errors.name ? true : undefined}>
                    <FieldLabel htmlFor="def-name">
                        {t('definitionEditor.name')}
                    </FieldLabel>
                    <Input id="def-name" {...register('name')} />
                    {err('name')}
                </Field>
                <Field>
                    <FieldLabel htmlFor="def-kind">
                        {t('definitionEditor.kind')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="kind"
                        render={({ field }) => (
                            <OptionSelect
                                id="def-kind"
                                value={field.value}
                                disabled={editing}
                                onValueChange={field.onChange}
                                items={options(KINDS, (kind) =>
                                    t(`reports.kinds.${kind}`),
                                )}
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('definitionEditor.kindHint')}
                    </FieldDescription>
                </Field>
                <Field>
                    <FieldLabel htmlFor="def-basis">
                        {t('definitionEditor.basis')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="basis"
                        render={({ field }) => (
                            <OptionSelect
                                id="def-basis"
                                value={field.value}
                                onValueChange={field.onChange}
                                items={options(BASES, (basis) =>
                                    t(`definitionEditor.bases.${basis}`),
                                )}
                            />
                        )}
                    />
                    <FieldDescription>
                        {t('definitionEditor.basisHint')}
                    </FieldDescription>
                </Field>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">
                    {t('definitionEditor.rows')}
                </h2>
                {rows.fields.map((row, index) => (
                    <RowCard
                        key={row.id}
                        index={index}
                        control={control}
                        register={register}
                        errors={errors}
                        accountItems={accountItems}
                        count={rows.fields.length}
                        onMove={(to) => rows.move(index, to)}
                        onRemove={() => rows.remove(index)}
                    />
                ))}
                {err('rows')}
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={() => rows.append(blankRow())}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('definitionEditor.addRow')}
                </Button>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">
                    {t('definitionEditor.columns')}
                </h2>
                {columns.fields.map((column, index) => (
                    <ColumnCard
                        key={column.id}
                        index={index}
                        control={control}
                        register={register}
                        errors={errors}
                        planVersions={(versions.data ?? []).map((v) => ({
                            value: v.id,
                            label: v.name,
                        }))}
                        onRemove={() => columns.remove(index)}
                    />
                ))}
                {err('columns')}
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={() => columns.append(blankColumn())}
                >
                    <PlusIcon data-icon="inline-start" />
                    {t('definitionEditor.addColumn')}
                </Button>
            </section>

            <SubmitButton
                className="w-auto self-start"
                pending={save.isPending}
                pendingLabel={t('common.saving')}
            >
                {editing
                    ? t('definitionEditor.saveChanges')
                    : t('definitionEditor.create')}
            </SubmitButton>
        </form>
    );
}

type Register = ReturnType<typeof useForm<DefinitionForm>>['register'];

function RowCard({
    index,
    control,
    register,
    errors,
    accountItems,
    count,
    onMove,
    onRemove,
}: {
    index: number;
    control: Control<DefinitionForm>;
    register: Register;
    errors: Errors;
    accountItems: { value: string; label: string }[];
    count: number;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const n = index + 1;
    const at = `rows.${index}`;
    const type = useWatch({ control, name: `rows.${index}.type` });
    const allRows = useWatch({ control, name: 'rows' });
    const earlier = allRows
        .slice(0, index)
        .map((row) => row.key)
        .filter(Boolean);
    const terms = useFieldArray({ control, name: `rows.${index}.terms` });
    const err = (path: string) =>
        errors[`${at}.${path}`] ? (
            <FieldError>{errors[`${at}.${path}`]}</FieldError>
        ) : null;
    const withSign = type !== 'heading';

    return (
        <fieldset
            aria-label={t('definitionEditor.rowN', { n })}
            className="flex flex-col gap-3 rounded-lg border p-4"
        >
            <div className="flex items-center justify-between">
                <legend className="text-sm font-medium">
                    {t('definitionEditor.rowN', { n })}
                </legend>
                <div className="flex gap-1">
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label={t('definitionEditor.moveUp', { n })}
                        disabled={index === 0}
                        onClick={() => onMove(index - 1)}
                    >
                        <ArrowUpIcon />
                    </Button>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label={t('definitionEditor.moveDown', { n })}
                        disabled={index === count - 1}
                        onClick={() => onMove(index + 1)}
                    >
                        <ArrowDownIcon />
                    </Button>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label={t('definitionEditor.removeRow', { n })}
                        onClick={onRemove}
                    >
                        <Trash2Icon />
                    </Button>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                <Field data-invalid={errors[`${at}.key`] ? true : undefined}>
                    <FieldLabel htmlFor={`row-${index}-key`}>
                        {t('definitionEditor.rowKey', { n })}
                    </FieldLabel>
                    <Input
                        id={`row-${index}-key`}
                        {...register(`rows.${index}.key`)}
                    />
                    {err('key')}
                </Field>
                <Field data-invalid={errors[`${at}.label`] ? true : undefined}>
                    <FieldLabel htmlFor={`row-${index}-label`}>
                        {t('definitionEditor.rowLabel', { n })}
                    </FieldLabel>
                    <Input
                        id={`row-${index}-label`}
                        {...register(`rows.${index}.label`)}
                    />
                    {err('label')}
                </Field>
                <Field data-invalid={errors[`${at}.type`] ? true : undefined}>
                    <FieldLabel htmlFor={`row-${index}-type`}>
                        {t('definitionEditor.rowType', { n })}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name={`rows.${index}.type`}
                        render={({ field }) => (
                            <OptionSelect
                                id={`row-${index}-type`}
                                value={field.value}
                                onValueChange={field.onChange}
                                items={options(ROW_TYPES, (value) =>
                                    t(`definitionEditor.rowTypes.${value}`),
                                )}
                            />
                        )}
                    />
                    {err('type')}
                </Field>
            </div>

            {withSign && (
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field>
                        <FieldLabel htmlFor={`row-${index}-sign`}>
                            {t('definitionEditor.rowSign', { n })}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name={`rows.${index}.sign`}
                            render={({ field }) => (
                                <OptionSelect
                                    id={`row-${index}-sign`}
                                    value={field.value}
                                    onValueChange={field.onChange}
                                    items={options(
                                        ['debit', 'credit'] as const,
                                        (value) =>
                                            t(
                                                `definitionEditor.signs.${value}`,
                                            ),
                                    )}
                                />
                            )}
                        />
                        {err('sign')}
                    </Field>
                    <Field>
                        <FieldLabel htmlFor={`row-${index}-favorable`}>
                            {t('definitionEditor.rowFavorable', { n })}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name={`rows.${index}.favorable`}
                            render={({ field }) => (
                                <OptionSelect
                                    id={`row-${index}-favorable`}
                                    value={field.value}
                                    onValueChange={field.onChange}
                                    placeholder={t(
                                        'definitionEditor.favorableAuto',
                                    )}
                                    items={options(
                                        ['', 'higher', 'lower'] as const,
                                        (value) =>
                                            value === ''
                                                ? t(
                                                      'definitionEditor.favorableAuto',
                                                  )
                                                : t(
                                                      `definitionEditor.favorables.${value}`,
                                                  ),
                                    )}
                                />
                            )}
                        />
                        {err('favorable')}
                    </Field>
                    {(type === 'accounts' || type === 'cash_equivalents') && (
                        <Field>
                            <FieldLabel htmlFor={`row-${index}-basis`}>
                                {t('definitionEditor.rowBasis', { n })}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name={`rows.${index}.basis`}
                                render={({ field }) => (
                                    <OptionSelect
                                        id={`row-${index}-basis`}
                                        value={field.value}
                                        onValueChange={field.onChange}
                                        items={options(
                                            ['', ...BASES] as const,
                                            (value) =>
                                                value === ''
                                                    ? t(
                                                          'definitionEditor.basisDefault',
                                                      )
                                                    : t(
                                                          `definitionEditor.bases.${value}`,
                                                      ),
                                        )}
                                    />
                                )}
                            />
                            {err('basis')}
                        </Field>
                    )}
                </div>
            )}

            {type === 'accounts' && (
                <div className="flex flex-col gap-2">
                    <div className="text-sm font-medium">
                        {t('definitionEditor.accountSet')}
                    </div>
                    <SelectorList
                        control={control as unknown as Control<FieldValues>}
                        name={`rows.${index}.accounts`}
                        errors={errors}
                        accountItems={accountItems}
                        labels={{
                            type: (s) =>
                                t('definitionEditor.selectorType', { n, s }),
                            class: (s) =>
                                t('definitionEditor.selectorClass', { n, s }),
                            account: (s) =>
                                t('definitionEditor.selectorAccount', { n, s }),
                            remove: (s) =>
                                t('definitionEditor.removeSelector', { n, s }),
                            add: t('definitionEditor.addSelector', { n }),
                        }}
                    />
                </div>
            )}

            {type === 'subtotal' && (
                <div className="flex flex-col gap-2">
                    <div className="text-sm font-medium">
                        {t('definitionEditor.terms')}
                    </div>
                    {terms.fields.map((term, s) => (
                        <div
                            key={term.id}
                            className="flex flex-wrap items-start gap-2"
                        >
                            <Controller
                                control={control}
                                name={`rows.${index}.terms.${s}.sign`}
                                render={({ field }) => (
                                    <OptionSelect
                                        value={field.value}
                                        onValueChange={field.onChange}
                                        className="w-28"
                                        aria-label={t(
                                            'definitionEditor.termSign',
                                            { n, t: s + 1 },
                                        )}
                                        items={[
                                            { value: '1', label: '+' },
                                            { value: '-1', label: '−' },
                                        ]}
                                    />
                                )}
                            />
                            <Controller
                                control={control}
                                name={`rows.${index}.terms.${s}.row`}
                                render={({ field }) => (
                                    <OptionSelect
                                        value={field.value}
                                        onValueChange={field.onChange}
                                        className="w-64"
                                        placeholder={t(
                                            'definitionEditor.termRow',
                                        )}
                                        aria-label={t(
                                            'definitionEditor.termRowN',
                                            { n, t: s + 1 },
                                        )}
                                        aria-invalid={
                                            errors[`${at}.terms.${s}.row`]
                                                ? true
                                                : undefined
                                        }
                                        items={[...earlier, UNMAPPED].map(
                                            (key) => ({
                                                value: key,
                                                label:
                                                    key === UNMAPPED
                                                        ? t(
                                                              'definitionEditor.unmappedRow',
                                                          )
                                                        : key,
                                            }),
                                        )}
                                    />
                                )}
                            />
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                aria-label={t('definitionEditor.removeTerm', {
                                    n,
                                    t: s + 1,
                                })}
                                onClick={() => terms.remove(s)}
                            >
                                <Trash2Icon />
                            </Button>
                            {errors[`${at}.terms.${s}.row`] && (
                                <FieldError className="basis-full">
                                    {errors[`${at}.terms.${s}.row`]}
                                </FieldError>
                            )}
                        </div>
                    ))}
                    {err('terms')}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="self-start"
                        onClick={() => terms.append({ row: '', sign: '1' })}
                    >
                        <PlusIcon data-icon="inline-start" />
                        {t('definitionEditor.addTerm', { n })}
                    </Button>
                </div>
            )}
        </fieldset>
    );
}

function ColumnCard({
    index,
    control,
    register,
    errors,
    planVersions,
    onRemove,
}: {
    index: number;
    control: Control<DefinitionForm>;
    register: Register;
    errors: Errors;
    planVersions: { value: string; label: string }[];
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const n = index + 1;
    const at = `columns.${index}`;
    const kind = useWatch({ control, name: `columns.${index}.kind` });
    const all = useWatch({ control, name: 'columns' });
    const earlier = all
        .slice(0, index)
        .filter((column) => column.key)
        .map((column) => ({ value: column.key, label: column.key }));
    const err = (path: string) =>
        errors[`${at}.${path}`] ? (
            <FieldError>{errors[`${at}.${path}`]}</FieldError>
        ) : null;

    return (
        <fieldset
            aria-label={t('definitionEditor.columnN', { n })}
            className="flex flex-col gap-3 rounded-lg border p-4"
        >
            <div className="flex items-center justify-between">
                <legend className="text-sm font-medium">
                    {t('definitionEditor.columnN', { n })}
                </legend>
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    aria-label={t('definitionEditor.removeColumn', { n })}
                    onClick={onRemove}
                >
                    <Trash2Icon />
                </Button>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field data-invalid={errors[`${at}.key`] ? true : undefined}>
                    <FieldLabel htmlFor={`col-${index}-key`}>
                        {t('definitionEditor.columnKey', { n })}
                    </FieldLabel>
                    <Input
                        id={`col-${index}-key`}
                        {...register(`columns.${index}.key`)}
                    />
                    {err('key')}
                </Field>
                <Field data-invalid={errors[`${at}.label`] ? true : undefined}>
                    <FieldLabel htmlFor={`col-${index}-label`}>
                        {t('definitionEditor.columnLabel', { n })}
                    </FieldLabel>
                    <Input
                        id={`col-${index}-label`}
                        {...register(`columns.${index}.label`)}
                    />
                    {err('label')}
                </Field>
                <Field data-invalid={errors[`${at}.kind`] ? true : undefined}>
                    <FieldLabel htmlFor={`col-${index}-kind`}>
                        {t('definitionEditor.columnKind', { n })}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name={`columns.${index}.kind`}
                        render={({ field }) => (
                            <OptionSelect
                                id={`col-${index}-kind`}
                                value={field.value}
                                onValueChange={field.onChange}
                                items={options(COLUMN_KINDS, (value) =>
                                    t(`definitionEditor.columnKinds.${value}`),
                                )}
                            />
                        )}
                    />
                    {err('kind')}
                </Field>
            </div>

            {kind === 'difference' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    {(['minuend', 'subtrahend'] as const).map((side) => (
                        <Field
                            key={side}
                            data-invalid={
                                errors[`${at}.${side}`] ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor={`col-${index}-${side}`}>
                                {t(`definitionEditor.${side}`, { n })}
                            </FieldLabel>
                            <Controller
                                control={control}
                                name={`columns.${index}.${side}`}
                                render={({ field }) => (
                                    <OptionSelect
                                        id={`col-${index}-${side}`}
                                        value={field.value}
                                        onValueChange={field.onChange}
                                        placeholder={t(
                                            'definitionEditor.pickColumn',
                                        )}
                                        items={earlier}
                                    />
                                )}
                            />
                            {err(side)}
                        </Field>
                    ))}
                </div>
            ) : (
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field
                        data-invalid={errors[`${at}.shift`] ? true : undefined}
                    >
                        <FieldLabel htmlFor={`col-${index}-years`}>
                            {t('definitionEditor.shiftYears', { n })}
                        </FieldLabel>
                        <Input
                            id={`col-${index}-years`}
                            inputMode="numeric"
                            placeholder="0"
                            {...register(`columns.${index}.shift_years`)}
                        />
                    </Field>
                    <Field
                        data-invalid={errors[`${at}.shift`] ? true : undefined}
                    >
                        <FieldLabel htmlFor={`col-${index}-months`}>
                            {t('definitionEditor.shiftMonths', { n })}
                        </FieldLabel>
                        <Input
                            id={`col-${index}-months`}
                            inputMode="numeric"
                            placeholder="0"
                            {...register(`columns.${index}.shift_months`)}
                        />
                        {err('shift')}
                    </Field>
                    {kind === 'plan' && (
                        <>
                            <Field>
                                <FieldLabel htmlFor={`col-${index}-span`}>
                                    {t('definitionEditor.planSpan', { n })}
                                </FieldLabel>
                                <Controller
                                    control={control}
                                    name={`columns.${index}.span`}
                                    render={({ field }) => (
                                        <OptionSelect
                                            id={`col-${index}-span`}
                                            value={field.value}
                                            onValueChange={field.onChange}
                                            items={options(
                                                ['period', 'ytd'] as const,
                                                (value) =>
                                                    t(
                                                        `definitionEditor.columnKinds.${value}`,
                                                    ),
                                            )}
                                        />
                                    )}
                                />
                                {err('span')}
                            </Field>
                            <Field className="sm:col-span-3">
                                <FieldLabel htmlFor={`col-${index}-plan`}>
                                    {t('definitionEditor.planVersion', { n })}
                                </FieldLabel>
                                <Controller
                                    control={control}
                                    name={`columns.${index}.plan_version_id`}
                                    render={({ field }) => (
                                        <SearchableSelect
                                            id={`col-${index}-plan`}
                                            items={planVersions}
                                            value={field.value}
                                            onValueChange={field.onChange}
                                            placeholder={t(
                                                'definitionEditor.planAuto',
                                            )}
                                            emptyText={t('common.noMatch')}
                                            clearable
                                            className="w-72"
                                        />
                                    )}
                                />
                                <FieldDescription>
                                    {t('definitionEditor.planAutoHint')}
                                </FieldDescription>
                                {err('plan_version_id')}
                            </Field>
                        </>
                    )}
                </div>
            )}
        </fieldset>
    );
}
