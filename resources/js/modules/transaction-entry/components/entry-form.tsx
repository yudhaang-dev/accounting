import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { FormAlert } from '@/components/form-alert';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { DatePicker } from '@/components/date-picker';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import {
    valuesFromReplaced,
    valuesOf,
    type Values,
} from '@/modules/transaction-entry/components/entry-values';
import { LinesEditor } from '@/modules/transaction-entry/components/lines-editor';
import { StructuredSection } from '@/modules/transaction-entry/components/structured-section';
import { TransactionTypeForm } from '@/modules/transaction-entry/components/transaction-type-form';
import {
    valuesBody,
    versionSchemaQuery,
} from '@/modules/transaction-entry/field-schema';
import {
    activeVersion,
    usableTypes,
} from '@/modules/transaction-entry/transaction-types';
import {
    createEntry,
    dimensionsBody,
    entryQuery,
    lineAccountsQuery,
    lineDimensionsQuery,
    transactionTypesQuery,
    updateEntry,
    type Entry,
    type LineAccount,
    type LineDimensionType,
    type TransactionType,
} from '@/modules/transaction-entry/entries';

function EntryFields({
    replaces,
    companyId,
    entry,
    types,
    accounts,
    dimensionTypes,
}: {
    companyId: string;
    entry?: Entry;
    /** A posted entry this one replaces (TE-FR-11); its content is the starting point. */
    replaces?: Entry;
    types: TransactionType[];
    accounts: LineAccount[];
    dimensionTypes: LineDimensionType[];
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: entry
            ? valuesOf(entry, '')
            : replaces
              ? valuesFromReplaced(replaces)
              : valuesOf(undefined, types[0]?.key ?? ''),
    });
    const {
        register,
        control,
        formState: { errors },
    } = form;
    const typeKey = useWatch({ control, name: 'transaction_type_key' });
    const type = types.find((candidate) => candidate.key === typeKey);
    // A draft keeps the version it was made against; a new entry takes the latest published one.
    const version =
        type &&
        ((entry &&
            type.versions.find(
                (candidate) =>
                    candidate.version === entry.transaction_type_version,
            )) ||
            activeVersion(type));
    const structured = version?.mode === 'structured';
    const schema = useQuery({
        ...versionSchemaQuery(companyId, type?.id ?? '', version?.version ?? 0),
        enabled: Boolean(structured && type && version),
    });

    const typeItems = types.map((type) => ({
        value: type.key,
        label: type.label,
    }));

    const save = useMutation({
        mutationFn: (values: Values) => {
            const linked = replaces?.id ?? entry?.replaces_entry_id ?? null;

            if (structured && version) {
                const common = {
                    accounting_date: values.accounting_date,
                    document_date: values.document_date || null,
                    description: values.description,
                    auto_reverse_on: values.auto_reverse_on || null,
                    replaces_entry_id: linked,
                    values: valuesBody(
                        schema.data ?? [],
                        values.values,
                    ) as unknown as string[],
                    currency: values.currency || null,
                    exchange_rate: values.exchange_rate || null,
                };

                return entry
                    ? updateEntry(companyId, entry.id, {
                          lock_version: entry.lock_version,
                          ...common,
                      })
                    : createEntry(companyId, {
                          transaction_type_key: values.transaction_type_key,
                          transaction_type_version: version.version,
                          ...common,
                      });
            }

            const lines = values.lines.map((line) => ({
                account_id: line.account_id,
                debit: line.debit || null,
                credit: line.credit || null,
                description: line.description || null,
                dimensions: dimensionsBody(line.dimensions),
            }));
            const common = {
                accounting_date: values.accounting_date,
                document_date: values.document_date || null,
                description: values.description,
                auto_reverse_on: values.auto_reverse_on || null,
                replaces_entry_id: linked,
                lines,
            };

            return entry
                ? updateEntry(companyId, entry.id, {
                      lock_version: entry.lock_version,
                      ...common,
                  })
                : createEntry(companyId, {
                      transaction_type_key: values.transaction_type_key,
                      ...common,
                  });
        },
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({
                queryKey: ['companies', companyId, 'entries'],
            });
            toast.success(t('entries.form.saved'));
            await navigate({
                to: '/w/$companyId/entries/$entryId',
                params: { companyId, entryId: saved.id },
            });
        },
        onError: async (error) => {
            if (
                isApiError(error) &&
                error.code === 'VERSION_CONFLICT' &&
                entry
            ) {
                const latest = await queryClient.fetchQuery({
                    ...entryQuery(companyId, entry.id),
                    staleTime: 0,
                });

                form.reset(valuesOf(latest, latest.transaction_type_key));
                setFormError(t('entries.form.conflict'));

                return;
            }

            if (
                isApiError(error) &&
                error.status === 422 &&
                applyApiErrors(form.setError, error)
            ) {
                return;
            }

            setFormError(
                isApiError(error) && error.status !== 0
                    ? error.message
                    : genericErrorMessage(error, t),
            );
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
                {replaces && (
                    <Alert>
                        <AlertDescription>
                            {t('entries.form.replacing', {
                                number: replaces.number,
                            })}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        data-invalid={
                            errors.transaction_type_key ? true : undefined
                        }
                    >
                        <FieldLabel htmlFor="entry-type">
                            {t('entries.form.type')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="transaction_type_key"
                            render={({ field }) => (
                                <Select
                                    value={field.value}
                                    items={typeItems}
                                    disabled={Boolean(entry || replaces)}
                                    onValueChange={field.onChange}
                                >
                                    <SelectTrigger
                                        id="entry-type"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {typeItems.map((item) => (
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
                        {errors.transaction_type_key && (
                            <FieldError>
                                {errors.transaction_type_key.message}
                            </FieldError>
                        )}
                    </Field>
                    <Field
                        data-invalid={errors.accounting_date ? true : undefined}
                    >
                        <FieldLabel id="entry-date-label" htmlFor="entry-date">
                            {t('entries.form.accountingDate')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="accounting_date"
                            rules={{ required: t('common.required') }}
                            render={({ field }) => (
                                <DatePicker
                                    id="entry-date"
                                    labelledBy="entry-date-label"
                                    value={field.value}
                                    onChange={field.onChange}
                                    aria-invalid={
                                        errors.accounting_date
                                            ? true
                                            : undefined
                                    }
                                />
                            )}
                        />
                        {errors.accounting_date && (
                            <FieldError>
                                {errors.accounting_date.message}
                            </FieldError>
                        )}
                    </Field>
                    <Field
                        data-invalid={errors.document_date ? true : undefined}
                    >
                        <FieldLabel
                            id="entry-document-date-label"
                            htmlFor="entry-document-date"
                        >
                            {t('entries.form.documentDate')}
                        </FieldLabel>
                        <Controller
                            control={control}
                            name="document_date"
                            render={({ field }) => (
                                <DatePicker
                                    id="entry-document-date"
                                    labelledBy="entry-document-date-label"
                                    value={field.value}
                                    onChange={field.onChange}
                                    clearable
                                    aria-invalid={
                                        errors.document_date ? true : undefined
                                    }
                                />
                            )}
                        />
                        {errors.document_date && (
                            <FieldError>
                                {errors.document_date.message}
                            </FieldError>
                        )}
                    </Field>
                </div>

                <Field
                    data-invalid={errors.auto_reverse_on ? true : undefined}
                    className="sm:max-w-xs"
                >
                    <FieldLabel
                        id="entry-auto-reverse-label"
                        htmlFor="entry-auto-reverse"
                    >
                        {t('entries.form.autoReverse')}
                    </FieldLabel>
                    <Controller
                        control={control}
                        name="auto_reverse_on"
                        render={({ field }) => (
                            <DatePicker
                                id="entry-auto-reverse"
                                labelledBy="entry-auto-reverse-label"
                                value={field.value}
                                onChange={field.onChange}
                                clearable
                                aria-invalid={
                                    errors.auto_reverse_on ? true : undefined
                                }
                            />
                        )}
                    />
                    {errors.auto_reverse_on ? (
                        <FieldError>
                            {errors.auto_reverse_on.message}
                        </FieldError>
                    ) : (
                        <FieldDescription>
                            {t('entries.form.autoReverseHint')}
                        </FieldDescription>
                    )}
                </Field>

                <Field data-invalid={errors.description ? true : undefined}>
                    <FieldLabel htmlFor="entry-description">
                        {t('entries.form.description')}
                    </FieldLabel>
                    <Textarea
                        id="entry-description"
                        rows={2}
                        maxLength={500}
                        aria-invalid={errors.description ? true : undefined}
                        {...register('description', {
                            validate: (value) =>
                                value.trim() !== '' || t('common.required'),
                        })}
                    />
                    {errors.description && (
                        <FieldError>{errors.description.message}</FieldError>
                    )}
                </Field>

                {structured && version ? (
                    schema.isError ? (
                        <FormAlert>
                            {genericErrorMessage(schema.error, t)}
                        </FormAlert>
                    ) : schema.data === undefined ? (
                        <Skeleton className="h-40 w-full" />
                    ) : (
                        <StructuredSection
                            companyId={companyId}
                            version={version}
                            fields={schema.data}
                            form={form}
                            accounts={accounts}
                            dimensionTypes={dimensionTypes}
                        />
                    )
                ) : (
                    <LinesEditor
                        form={form}
                        accounts={accounts}
                        dimensionTypes={dimensionTypes}
                    />
                )}

                <div className="flex flex-wrap gap-2">
                    <SubmitButton
                        className="w-auto"
                        pending={save.isPending}
                        pendingLabel={t('entries.form.saving')}
                    >
                        {t('entries.form.save')}
                    </SubmitButton>
                    <Button
                        variant="ghost"
                        render={
                            entry ? (
                                <Link
                                    to="/w/$companyId/entries/$entryId"
                                    params={{ companyId, entryId: entry.id }}
                                />
                            ) : (
                                <Link
                                    to="/w/$companyId/entries"
                                    params={{ companyId }}
                                />
                            )
                        }
                    >
                        {t('entries.form.cancel')}
                    </Button>
                </div>
            </FieldGroup>
        </form>
    );
}

/**
 * Form of a draft entry (TE-FR-03, `free_lines`): header, balanced debit/credit lines, and the
 * running totals. Amounts stay decimal strings from keyboard to API (API-TR-05).
 */
export function EntryForm({
    replaces,
    companyId,
    entry,
    canManageTypes,
    canUseDimensions,
}: {
    companyId: string;
    entry?: Entry;
    /** A posted entry the new one replaces. */
    replaces?: Entry;
    canManageTypes: boolean;
    /** Whether the user may read dimensions, and so pick them on a line. */
    canUseDimensions: boolean;
}) {
    const { t } = useTranslation();
    const types = useQuery(transactionTypesQuery(companyId));
    const accounts = useQuery(lineAccountsQuery(companyId));
    // Dimensions are optional on a line: if they cannot be loaded the form still works without them.
    const dimensions = useQuery({
        ...lineDimensionsQuery(companyId),
        enabled: canUseDimensions,
    });

    if (types.isError || accounts.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(types.error ?? accounts.error, t)}
            </FormAlert>
        );
    }

    if (
        types.data === undefined ||
        accounts.data === undefined ||
        (canUseDimensions && dimensions.isPending)
    ) {
        return <Skeleton className="h-96 w-full" />;
    }

    const usable = types.data.filter(
        (type) =>
            usableTypes([type]).length > 0 ||
            type.key === entry?.transaction_type_key,
    );

    if (types.data.length === 0) {
        return canManageTypes ? (
            <TransactionTypeForm companyId={companyId} />
        ) : (
            <FormAlert>{t('entries.types.noneDescription')}</FormAlert>
        );
    }

    if (usable.length === 0) {
        return <FormAlert>{t('entries.types.noneUsable')}</FormAlert>;
    }

    return (
        <EntryFields
            companyId={companyId}
            entry={entry}
            replaces={replaces}
            types={usable}
            accounts={accounts.data}
            dimensionTypes={dimensions.data ?? []}
        />
    );
}
