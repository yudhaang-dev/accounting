import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { PlusIcon, Trash2Icon } from 'lucide-react';
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
import { SubmitButton } from '@/components/submit-button';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { DatePicker } from '@/components/date-picker';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
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
import {
    differenceDecimals,
    formatDecimal,
    isDecimal,
    isPositive,
    sumDecimals,
} from '@/lib/decimal';
import { todayIso } from '@/lib/date';
import { genericErrorMessage } from '@/lib/errors';
import { applyApiErrors } from '@/lib/forms';
import { isApiError } from '@/lib/http';
import { TransactionTypeForm } from '@/modules/transaction-entry/components/transaction-type-form';
import {
    createEntry,
    entryQuery,
    lineAccountsQuery,
    transactionTypesQuery,
    updateEntry,
    type Entry,
    type LineAccount,
    type TransactionType,
} from '@/modules/transaction-entry/entries';

type Line = {
    account_id: string;
    debit: string;
    credit: string;
    description: string;
};

type Values = {
    transaction_type_key: string;
    accounting_date: string;
    document_date: string;
    description: string;
    lines: Line[];
};

const blankLine = (): Line => ({
    account_id: '',
    debit: '',
    credit: '',
    description: '',
});

const valuesOf = (entry: Entry | undefined, typeKey: string): Values =>
    entry
        ? {
              transaction_type_key: entry.transaction_type_key,
              accounting_date: entry.accounting_date,
              document_date: entry.document_date ?? '',
              description: entry.description,
              lines: entry.lines.map((line) => ({
                  account_id: line.account_id,
                  // The API answers "0" for the side a line does not use.
                  debit: isPositive(line.debit) ? line.debit : '',
                  credit: isPositive(line.credit) ? line.credit : '',
                  description: line.description ?? '',
              })),
          }
        : {
              transaction_type_key: typeKey,
              accounting_date: todayIso(),
              document_date: '',
              description: '',
              lines: [blankLine(), blankLine()],
          };

function Totals({ control }: { control: Control<Values> }) {
    const { t, i18n } = useTranslation();
    const lines = useWatch({ control, name: 'lines' });
    const debit = sumDecimals(lines.map((line) => line.debit));
    const credit = sumDecimals(lines.map((line) => line.credit));
    const gap = differenceDecimals(debit, credit);
    const balanced = !isPositive(gap);

    return (
        <div
            className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
            aria-live="polite"
        >
            <span>
                {t('entries.form.totalDebit')}:{' '}
                <span className="font-medium tabular-nums">
                    {formatDecimal(debit, i18n.language)}
                </span>
            </span>
            <span>
                {t('entries.form.totalCredit')}:{' '}
                <span className="font-medium tabular-nums">
                    {formatDecimal(credit, i18n.language)}
                </span>
            </span>
            <span
                className={
                    balanced ? 'font-medium' : 'font-medium text-destructive'
                }
            >
                {balanced
                    ? t('entries.form.balanced')
                    : t('entries.form.difference', {
                          amount: formatDecimal(gap, i18n.language),
                      })}
            </span>
        </div>
    );
}

function EntryFields({
    companyId,
    entry,
    types,
    accounts,
}: {
    companyId: string;
    entry?: Entry;
    types: TransactionType[];
    accounts: LineAccount[];
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<Values>({
        defaultValues: valuesOf(entry, types[0]?.key ?? ''),
    });
    const {
        register,
        control,
        setValue,
        formState: { errors },
    } = form;
    const { fields, append, remove } = useFieldArray({
        control,
        name: 'lines',
    });

    const accountItems = accounts.map((account) => ({
        value: account.id,
        label: `${account.code} · ${account.name}`,
    }));
    const typeItems = types.map((type) => ({
        value: type.key,
        label: type.label,
    }));

    const save = useMutation({
        mutationFn: (values: Values) => {
            const lines = values.lines.map((line) => ({
                account_id: line.account_id,
                debit: line.debit || null,
                credit: line.credit || null,
                description: line.description || null,
            }));
            const common = {
                accounting_date: values.accounting_date,
                document_date: values.document_date || null,
                description: values.description,
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

    // A line has either a debit or a credit: typing on one side clears the other.
    const onSide = (index: number, side: 'debit' | 'credit', value: string) => {
        if (value !== '') {
            setValue(
                `lines.${index}.${side === 'debit' ? 'credit' : 'debit'}`,
                '',
            );
        }
    };

    const amountRules = (index: number, side: 'debit' | 'credit') => ({
        validate: (value: string, values: Values) => {
            const other =
                values.lines[index]?.[side === 'debit' ? 'credit' : 'debit'];

            if (value === '') {
                return other !== '' || t('entries.form.oneSide');
            }

            return (
                (isDecimal(value) && isPositive(value)) ||
                t('entries.form.amountInvalid')
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
                                    disabled={Boolean(entry)}
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

                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium">
                        {t('entries.form.lines')}
                    </h2>

                    <ul className="flex flex-col gap-3">
                        {fields.map((field, index) => {
                            const n = index + 1;
                            const row = errors.lines?.[index];
                            // Every problem of the line, once each: a missing account must not hide a bad amount.
                            const rowMessages = [
                                ...new Set(
                                    [
                                        row?.account_id?.message,
                                        row?.debit?.message,
                                        row?.credit?.message,
                                        row?.description?.message,
                                    ].filter((message): message is string =>
                                        Boolean(message),
                                    ),
                                ),
                            ];

                            return (
                                <li
                                    key={field.id}
                                    className="flex flex-col gap-2 rounded-lg border p-3"
                                >
                                    <div className="grid gap-2 lg:grid-cols-[minmax(0,2fr)_9rem_9rem_minmax(0,1.5fr)_auto]">
                                        <Controller
                                            control={control}
                                            name={`lines.${index}.account_id`}
                                            rules={{
                                                required: t('common.required'),
                                            }}
                                            render={({ field: account }) => (
                                                <SearchableSelect
                                                    items={accountItems}
                                                    value={account.value}
                                                    onValueChange={
                                                        account.onChange
                                                    }
                                                    placeholder={t(
                                                        'entries.form.chooseAccount',
                                                    )}
                                                    emptyText={t(
                                                        'common.noMatch',
                                                    )}
                                                    aria-label={`${t('entries.form.account')} ${t('entries.form.line', { n })}`}
                                                    aria-invalid={
                                                        row?.account_id
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                            )}
                                        />
                                        {(['debit', 'credit'] as const).map(
                                            (side) => (
                                                <Controller
                                                    key={side}
                                                    control={control}
                                                    name={`lines.${index}.${side}`}
                                                    rules={amountRules(
                                                        index,
                                                        side,
                                                    )}
                                                    render={({
                                                        field: money,
                                                    }) => (
                                                        <MoneyInput
                                                            placeholder={t(
                                                                `entries.form.${side}`,
                                                            )}
                                                            aria-label={`${t(`entries.form.${side}`)} ${t('entries.form.line', { n })}`}
                                                            aria-invalid={
                                                                row?.[side]
                                                                    ? true
                                                                    : undefined
                                                            }
                                                            value={money.value}
                                                            onBlur={
                                                                money.onBlur
                                                            }
                                                            onChange={(
                                                                next,
                                                            ) => {
                                                                money.onChange(
                                                                    next,
                                                                );
                                                                onSide(
                                                                    index,
                                                                    side,
                                                                    next,
                                                                );
                                                            }}
                                                        />
                                                    )}
                                                />
                                            ),
                                        )}
                                        <Input
                                            placeholder={t(
                                                'entries.form.lineDescription',
                                            )}
                                            aria-label={`${t('entries.form.lineDescription')} ${t('entries.form.line', { n })}`}
                                            maxLength={500}
                                            {...register(
                                                `lines.${index}.description`,
                                            )}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={fields.length <= 2}
                                            aria-label={t(
                                                'entries.form.removeLine',
                                                { n },
                                            )}
                                            onClick={() => remove(index)}
                                        >
                                            <Trash2Icon aria-hidden />
                                        </Button>
                                    </div>
                                    {rowMessages.length > 0 && (
                                        <ul
                                            role="alert"
                                            className="flex flex-col gap-0.5 text-sm text-destructive"
                                        >
                                            {rowMessages.map((message) => (
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
                            onClick={() => append(blankLine())}
                        >
                            <PlusIcon data-icon="inline-start" />
                            {t('entries.form.addLine')}
                        </Button>
                    </div>

                    <Totals control={control} />
                </div>

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
    companyId,
    entry,
    canManageTypes,
}: {
    companyId: string;
    entry?: Entry;
    canManageTypes: boolean;
}) {
    const { t } = useTranslation();
    const types = useQuery(transactionTypesQuery(companyId));
    const accounts = useQuery(lineAccountsQuery(companyId));

    if (types.isError || accounts.isError) {
        return (
            <FormAlert>
                {genericErrorMessage(types.error ?? accounts.error, t)}
            </FormAlert>
        );
    }

    if (types.data === undefined || accounts.data === undefined) {
        return <Skeleton className="h-96 w-full" />;
    }

    if (types.data.length === 0) {
        return canManageTypes ? (
            <TransactionTypeForm companyId={companyId} />
        ) : (
            <FormAlert>{t('entries.types.noneDescription')}</FormAlert>
        );
    }

    return (
        <EntryFields
            companyId={companyId}
            entry={entry}
            types={types.data}
            accounts={accounts.data}
        />
    );
}
