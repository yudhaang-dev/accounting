import { useQuery } from '@tanstack/react-query';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { Controller, useWatch, type UseFormReturn } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { formatDecimal } from '@/lib/decimal';
import { cashAccountsQuery } from '@/modules/subledger/cash';
import {
    partyOpenItemsQuery,
    partyOptionsQuery,
} from '@/modules/subledger/documents';
import type { Values } from '@/modules/transaction-entry/components/entry-values';
import type { AllocationRow } from '@/modules/transaction-entry/field-schema';

type InputProps = {
    companyId: string;
    id: string;
    name: `values.${string}`;
    form: UseFormReturn<Values>;
    required: boolean;
    rules: { validate: (value: string | undefined) => true | string };
    invalid: boolean;
};

/** Picks the party of a document (SL-FR-01); an inactive party is offered only while it is the chosen one. */
export function PartyInput({
    companyId,
    id,
    name,
    form,
    required,
    rules,
    invalid,
}: InputProps) {
    const { t } = useTranslation();

    const parties = useQuery(partyOptionsQuery(companyId));

    return (
        <Controller
            control={form.control}
            name={name}
            rules={rules}
            render={({ field }) => (
                <SearchableSelect
                    id={id}
                    items={(parties.data ?? [])
                        .filter(
                            (party) =>
                                party.status === 'active' ||
                                party.id === field.value,
                        )
                        .map((party) => ({
                            value: party.id,
                            label: party.name,
                        }))}
                    value={field.value ?? ''}
                    onValueChange={field.onChange}
                    placeholder={t('entries.form.chooseParty')}
                    emptyText={t('common.noMatch')}
                    clearable={!required}
                    aria-invalid={invalid ? true : undefined}
                />
            )}
        />
    );
}

/** Picks the cash or bank account a document moves cash through (SL-FR-03). */
export function CashAccountInput({
    companyId,
    id,
    name,
    form,
    required,
    rules,
    invalid,
}: InputProps) {
    const { t } = useTranslation();

    const accounts = useQuery(cashAccountsQuery(companyId));

    return (
        <Controller
            control={form.control}
            name={name}
            rules={rules}
            render={({ field }) => (
                <SearchableSelect
                    id={id}
                    items={(accounts.data ?? [])
                        .filter(
                            (account) =>
                                account.status === 'active' ||
                                account.id === field.value,
                        )
                        .map((account) => ({
                            value: account.id,
                            label: `${account.name} · ${account.currency}`,
                        }))}
                    value={field.value ?? ''}
                    onValueChange={field.onChange}
                    placeholder={t('entries.form.chooseCashAccount')}
                    emptyText={t('common.noMatch')}
                    clearable={!required}
                    aria-invalid={invalid ? true : undefined}
                />
            )}
        />
    );
}

const emptyRow = (): AllocationRow => ({ open_item_id: '', amount: '' });

/**
 * The open items of the party an entry settles and how much of each (SL-FR-05). The party is the one the
 * route of the version takes from its party field; until it is chosen there is nothing to settle.
 */
export function AllocationsInput({
    companyId,
    id,
    name,
    form,
    partyField,
    messages,
}: {
    companyId: string;
    id: string;
    name: string;
    form: UseFormReturn<Values>;
    /** The field that holds the party, or null when the version has no route. */
    partyField: string | null;
    messages: string[];
}) {
    const { t, i18n } = useTranslation();

    const partyId =
        useWatch({
            control: form.control,
            name: `values.${partyField ?? '__none'}`,
        }) ?? '';
    const items = useQuery({
        ...partyOpenItemsQuery(companyId, partyId),
        enabled: partyId !== '',
    });
    const path = `allocations.${name}` as const;

    return (
        <Controller
            control={form.control}
            name={path}
            render={({ field }) => {
                const rows: AllocationRow[] = field.value ?? [];
                const change = (index: number, patch: Partial<AllocationRow>) =>
                    field.onChange(
                        rows.map((row, at) =>
                            at === index ? { ...row, ...patch } : row,
                        ),
                    );

                return (
                    <div id={id} className="flex flex-col gap-2">
                        {partyId === '' && (
                            <p className="text-sm text-muted-foreground">
                                {t('entries.form.allocationsNeedParty')}
                            </p>
                        )}
                        {partyId !== '' &&
                            items.data?.length === 0 &&
                            rows.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {t('entries.form.noOpenItems')}
                                </p>
                            )}
                        <ul className="flex flex-col gap-2">
                            {rows.map((row, index) => {
                                const n = index + 1;

                                return (
                                    <li
                                        key={index}
                                        className="grid gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]"
                                    >
                                        <SearchableSelect
                                            items={(items.data ?? []).map(
                                                (item) => ({
                                                    value: item.id,
                                                    label: t(
                                                        'entries.form.openItemLabel',
                                                        {
                                                            number: item.document_number,
                                                            remaining:
                                                                formatDecimal(
                                                                    item.remaining_amount,
                                                                    i18n.language,
                                                                ),
                                                            currency:
                                                                item.currency,
                                                        },
                                                    ),
                                                }),
                                            )}
                                            value={row.open_item_id}
                                            onValueChange={(next) =>
                                                change(index, {
                                                    open_item_id: next,
                                                })
                                            }
                                            placeholder={t(
                                                'entries.form.chooseOpenItem',
                                            )}
                                            emptyText={t('common.noMatch')}
                                            aria-label={t(
                                                'entries.form.allocationItem',
                                                { n },
                                            )}
                                        />
                                        <MoneyInput
                                            value={row.amount}
                                            onChange={(next) =>
                                                change(index, { amount: next })
                                            }
                                            aria-label={t(
                                                'entries.form.allocationAmount',
                                                { n },
                                            )}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t(
                                                'entries.form.removeAllocation',
                                                { n },
                                            )}
                                            onClick={() =>
                                                field.onChange(
                                                    rows.filter(
                                                        (_, at) => at !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2Icon aria-hidden />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={partyId === ''}
                                onClick={() =>
                                    field.onChange([...rows, emptyRow()])
                                }
                            >
                                <PlusIcon data-icon="inline-start" />
                                {t('entries.form.addAllocation')}
                            </Button>
                        </div>
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
                    </div>
                );
            }}
        />
    );
}

/** Every message an error holds, however deep the server put it (a row of the allocations has its own). */
export function messagesOf(error: unknown): string[] {
    if (typeof error !== 'object' || error === null) {
        return [];
    }

    const { message, ...rest } = error as { message?: unknown } & Record<
        string,
        unknown
    >;

    return [
        ...(typeof message === 'string' && message !== '' ? [message] : []),
        ...Object.entries(rest)
            .filter(([key]) => key !== 'type' && key !== 'ref')
            .flatMap(([, nested]) => messagesOf(nested)),
    ];
}
