import { PlusIcon, Trash2Icon } from 'lucide-react';
import {
    Controller,
    useFieldArray,
    useWatch,
    type Control,
    type UseFormReturn,
} from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    differenceDecimals,
    formatDecimal,
    isDecimal,
    isPositive,
    sumDecimals,
} from '@/lib/decimal';
import {
    blankLine,
    type Values,
} from '@/modules/transaction-entry/components/entry-values';
import type {
    LineAccount,
    LineDimensionType,
} from '@/modules/transaction-entry/entries';

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

/**
 * One picker per dimension type under a line (MD-FR-04). Only values valid on the entry's accounting
 * date are offered, plus the one already chosen, so a value that stopped being valid stays visible and
 * the server can say why it is refused.
 */
function LineDimensions({
    control,
    index,
    types,
    rowErrors,
}: {
    control: Control<Values>;
    index: number;
    types: LineDimensionType[];
    rowErrors: Record<string, { message?: string } | undefined> | undefined;
}) {
    const { t } = useTranslation();
    const date = useWatch({ control, name: 'accounting_date' });
    const chosen = useWatch({ control, name: `lines.${index}.dimensions` });

    return (
        <div className="flex flex-wrap gap-2">
            {types.map((type) => {
                const items = type.values
                    .filter(
                        (value) =>
                            value.id === chosen?.[type.key] ||
                            ((!value.valid_from || value.valid_from <= date) &&
                                (!value.valid_to || value.valid_to >= date)),
                    )
                    .map((value) => ({
                        value: value.id,
                        label: `${value.code} · ${value.name}`,
                    }));

                return (
                    <Controller
                        key={type.key}
                        control={control}
                        name={`lines.${index}.dimensions.${type.key}`}
                        render={({ field }) => (
                            <SearchableSelect
                                items={items}
                                value={field.value ?? ''}
                                onValueChange={field.onChange}
                                placeholder={type.label}
                                emptyText={t('common.noMatch')}
                                clearable
                                className="w-52"
                                aria-label={`${type.label} ${t('entries.form.line', { n: index + 1 })}`}
                                aria-invalid={
                                    rowErrors?.[type.key] ? true : undefined
                                }
                            />
                        )}
                    />
                );
            })}
        </div>
    );
}

/**
 * The debit and credit lines of a free-lines entry, or of the template of a recurring schedule: accounts,
 * one-sided amounts as exact decimal strings, dimensions, and running totals (TE-FR-03).
 */
export function LinesEditor({
    form,
    accounts,
    dimensionTypes,
}: {
    form: UseFormReturn<Values>;
    accounts: LineAccount[];
    dimensionTypes: LineDimensionType[];
}) {
    const { t } = useTranslation();
    const {
        control,
        register,
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
        <div className="flex flex-col gap-3">
            <h2 className="text-sm font-medium">{t('entries.form.lines')}</h2>

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
                                ...Object.values(
                                    (row?.dimensions ?? {}) as Record<
                                        string,
                                        { message?: string } | undefined
                                    >,
                                ).map((error) => error?.message),
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
                                            onValueChange={account.onChange}
                                            placeholder={t(
                                                'entries.form.chooseAccount',
                                            )}
                                            emptyText={t('common.noMatch')}
                                            aria-label={`${t('entries.form.account')} ${t('entries.form.line', { n })}`}
                                            aria-invalid={
                                                row?.account_id
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    )}
                                />
                                {(['debit', 'credit'] as const).map((side) => (
                                    <Controller
                                        key={side}
                                        control={control}
                                        name={`lines.${index}.${side}`}
                                        rules={amountRules(index, side)}
                                        render={({ field: money }) => (
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
                                                onBlur={money.onBlur}
                                                onChange={(next) => {
                                                    money.onChange(next);
                                                    onSide(index, side, next);
                                                }}
                                            />
                                        )}
                                    />
                                ))}
                                <Input
                                    placeholder={t(
                                        'entries.form.lineDescription',
                                    )}
                                    aria-label={`${t('entries.form.lineDescription')} ${t('entries.form.line', { n })}`}
                                    maxLength={500}
                                    {...register(`lines.${index}.description`)}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={fields.length <= 2}
                                    aria-label={t('entries.form.removeLine', {
                                        n,
                                    })}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2Icon aria-hidden />
                                </Button>
                            </div>
                            {dimensionTypes.length > 0 && (
                                <LineDimensions
                                    control={control}
                                    index={index}
                                    types={dimensionTypes}
                                    rowErrors={
                                        row?.dimensions as
                                            | Record<
                                                  string,
                                                  | {
                                                        message?: string;
                                                    }
                                                  | undefined
                                              >
                                            | undefined
                                    }
                                />
                            )}
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
    );
}
