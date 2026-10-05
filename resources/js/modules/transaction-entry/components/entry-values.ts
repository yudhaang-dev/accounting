import { todayIso } from '@/lib/date';
import { isPositive } from '@/lib/decimal';
import type { Entry } from '@/modules/transaction-entry/entries';
import {
    allocationValues,
    type AllocationRow,
} from '@/modules/transaction-entry/field-schema';

export type Line = {
    account_id: string;
    debit: string;
    credit: string;
    description: string;
    /** Dimension type key to value id; a type left out has no value. */
    dimensions: Record<string, string>;
};

/** What the entry form holds: the header, and either the lines (free lines) or the field values (structured). */
export type Values = {
    transaction_type_key: string;
    accounting_date: string;
    document_date: string;
    description: string;
    lines: Line[];
    /** Field name to value, for a structured type. */
    values: Record<string, string>;
    /** Field name to the open items an `allocations` field settles, for a structured type. */
    allocations: Record<string, AllocationRow[]>;
    /** Empty means the functional currency. */
    currency: string;
    exchange_rate: string;
    /** Date the system reverses the entry on once it is posted; empty for none (TE-FR-11). */
    auto_reverse_on: string;
};

export const blankLine = (): Line => ({
    account_id: '',
    debit: '',
    credit: '',
    description: '',
    dimensions: {},
});

/** The stored values are of any JSON type; the form keeps text, and a structured version only has text-shaped fields. */
const textValues = (values: Entry['values']): Record<string, string> =>
    Object.fromEntries(
        Object.entries((values ?? {}) as Record<string, unknown>)
            .filter(([, value]) => typeof value === 'string')
            .map(([name, value]) => [name, value as string]),
    );

export const valuesOf = (entry: Entry | undefined, typeKey: string): Values =>
    entry
        ? {
              transaction_type_key: entry.transaction_type_key,
              accounting_date: entry.accounting_date,
              document_date: entry.document_date ?? '',
              description: entry.description,
              // A structured entry's lines are made by the posting rules; they are not typed in.
              lines: entry.values
                  ? [blankLine(), blankLine()]
                  : entry.lines.map((line) => ({
                        account_id: line.account_id,
                        // The API answers "0" for the side a line does not use.
                        debit: isPositive(line.debit) ? line.debit : '',
                        credit: isPositive(line.credit) ? line.credit : '',
                        description: line.description ?? '',
                        dimensions: { ...line.dimensions },
                    })),
              values: textValues(entry.values),
              allocations: allocationValues(
                  entry.values as Record<string, unknown> | null,
              ),
              currency: entry.currency ?? '',
              exchange_rate: entry.exchange_rate ?? '',
              auto_reverse_on: entry.auto_reverse_on ?? '',
          }
        : {
              transaction_type_key: typeKey,
              accounting_date: todayIso(),
              document_date: '',
              description: '',
              lines: [blankLine(), blankLine()],
              values: {},
              allocations: {},
              currency: '',
              exchange_rate: '',
              auto_reverse_on: '',
          };

/** A new entry that starts as a copy of a posted one: same type and content, dated today, with no schedule of its own. */
export const valuesFromReplaced = (source: Entry): Values => ({
    ...valuesOf(source, source.transaction_type_key),
    accounting_date: todayIso(),
    auto_reverse_on: '',
});
