import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { parseCsv } from '@/lib/csv';
import {
    compareDecimals,
    differenceDecimals,
    isDecimal,
    sumDecimals,
} from '@/lib/decimal';
import { isApiError } from '@/lib/http';

export type OpeningBatch = components['schemas']['OpeningBalanceBatchResource'];
export type HistoryImport = components['schemas']['HistoryImportResource'];
export type OpeningBalancesInput =
    components['schemas']['ImportOpeningBalancesRequest'];
export type HistoryInput = components['schemas']['ImportHistoryRequest'];
export type OpeningOpenItemInput = NonNullable<
    OpeningBalancesInput['open_items']
>[number];

export type Granularity = 'period_summary' | 'transactions';
export const GRANULARITIES: Granularity[] = ['period_summary', 'transactions'];

/** One balance of the source system, on one side (GL-FR-11). */
export type SourceLine = {
    source_account_code: string;
    debit: string;
    credit: string;
    description: string | null;
};

export type SourceJournal = {
    accounting_date: string;
    description: string | null;
    lines: SourceLine[];
};

/** What is wrong with a row of the file; the screen words it in the user's language. */
export type Problem = {
    /** Line of the file, counting its header as line 1. */
    line: number;
    kind: 'column' | 'amount' | 'date' | 'code' | 'role' | 'document';
    /** The column that is missing, or the value that was refused. */
    detail: string;
};

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function columns(
    header: string[],
    required: string[],
    problems: Problem[],
): Map<string, number> {
    const index = new Map(header.map((name, i) => [name.toLowerCase(), i]));

    for (const name of required) {
        if (!index.has(name)) {
            problems.push({ line: 1, kind: 'column', detail: name });
        }
    }

    return index;
}

const amount = (value: string | undefined): string | null => {
    const text = (value ?? '').trim();

    return text === '' ? '0' : isDecimal(text) ? text : null;
};

/**
 * Brings the lines of one account to a single side: an account that appears twice, or with both a
 * debit and a credit, is carried by the difference. Accounts that net to zero have no balance to carry.
 */
export function netLines(
    entries: {
        code: string;
        debit: string;
        credit: string;
        description?: string | null;
    }[],
): SourceLine[] {
    const byCode = new Map<string, typeof entries>();

    for (const entry of entries) {
        byCode.set(entry.code, [...(byCode.get(entry.code) ?? []), entry]);
    }

    const lines: SourceLine[] = [];

    for (const [code, group] of byCode) {
        const debit = sumDecimals(group.map((entry) => entry.debit));
        const credit = sumDecimals(group.map((entry) => entry.credit));
        const order = compareDecimals(debit, credit);

        if (order === 0) {
            continue;
        }

        const net = differenceDecimals(debit, credit);

        lines.push({
            source_account_code: code,
            debit: order > 0 ? net : '0',
            credit: order < 0 ? net : '0',
            description:
                group.find((entry) => entry.description)?.description ?? null,
        });
    }

    return lines;
}

/** Reads `source_code, debit, credit[, description]`, one row per balance of the source trial balance. */
export function parseBalances(text: string): {
    lines: SourceLine[];
    problems: Problem[];
} {
    const rows = parseCsv(text);
    const problems: Problem[] = [];
    const header = rows[0]?.map((name) => name.toLowerCase()) ?? [];
    const index = columns(header, ['source_code', 'debit', 'credit'], problems);
    const entries: Parameters<typeof netLines>[0] = [];

    if (problems.length === 0) {
        rows.slice(1).forEach((row, i) => {
            const line = i + 2;
            const code = row[index.get('source_code') ?? 0] ?? '';
            const debit = amount(row[index.get('debit') ?? 0]);
            const credit = amount(row[index.get('credit') ?? 0]);

            if (code === '') {
                problems.push({ line, kind: 'code', detail: '' });
            } else if (debit === null || credit === null) {
                problems.push({
                    line,
                    kind: 'amount',
                    detail:
                        (debit === null
                            ? row[index.get('debit') ?? 0]
                            : row[index.get('credit') ?? 0]) ?? '',
                });
            } else {
                entries.push({
                    code,
                    debit,
                    credit,
                    description: row[index.get('description') ?? -1] || null,
                });
            }
        });
    }

    return { lines: netLines(entries), problems };
}

/**
 * Reads `date, journal, source_code, debit, credit[, description]`. Rows with the same date and
 * journal reference form one journal; without a reference, the rows of a date do (one summary journal).
 */
export function parseJournals(text: string): {
    journals: SourceJournal[];
    problems: Problem[];
} {
    const rows = parseCsv(text);
    const problems: Problem[] = [];
    const header = rows[0]?.map((name) => name.toLowerCase()) ?? [];
    const index = columns(
        header,
        ['date', 'source_code', 'debit', 'credit'],
        problems,
    );
    const groups = new Map<
        string,
        {
            date: string;
            entries: Parameters<typeof netLines>[0];
            description: string | null;
        }
    >();

    if (problems.length === 0) {
        rows.slice(1).forEach((row, i) => {
            const line = i + 2;
            const date = row[index.get('date') ?? 0] ?? '';
            const reference = row[index.get('journal') ?? -1] ?? '';
            const code = row[index.get('source_code') ?? 0] ?? '';
            const debit = amount(row[index.get('debit') ?? 0]);
            const credit = amount(row[index.get('credit') ?? 0]);

            if (!ISO_DATE.test(date)) {
                problems.push({ line, kind: 'date', detail: date });
            } else if (code === '') {
                problems.push({ line, kind: 'code', detail: '' });
            } else if (debit === null || credit === null) {
                problems.push({
                    line,
                    kind: 'amount',
                    detail:
                        (debit === null
                            ? row[index.get('debit') ?? 0]
                            : row[index.get('credit') ?? 0]) ?? '',
                });
            } else {
                const key = `${date}|${reference}`;
                const group = groups.get(key) ?? {
                    date,
                    entries: [],
                    description: reference || null,
                };

                group.entries.push({
                    code,
                    debit,
                    credit,
                    description: row[index.get('description') ?? -1] || null,
                });
                groups.set(key, group);
            }
        });
    }

    return {
        journals: [...groups.values()].map((group) => ({
            accounting_date: group.date,
            description: group.description,
            lines: netLines(group.entries),
        })),
        problems,
    };
}

/** Total debit and credit of lines, exactly. */
export function totalsOf(lines: SourceLine[]): {
    debit: string;
    credit: string;
} {
    return {
        debit: sumDecimals(lines.map((line) => line.debit)),
        credit: sumDecimals(lines.map((line) => line.credit)),
    };
}

export const isBalanced = (lines: SourceLine[]): boolean => {
    const { debit, credit } = totalsOf(lines);

    return compareDecimals(debit, credit) === 0;
};

/** The distinct source account codes of lines, in order of first appearance. */
export const codesOf = (lines: SourceLine[]): string[] => [
    ...new Set(lines.map((line) => line.source_account_code)),
];

/** The opening balance batch of the company, or null before it was imported. */
export const openingBatchQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'opening-balance-imports'],
        queryFn: async (): Promise<OpeningBatch | null> => {
            try {
                return (
                    await unwrap(
                        api.GET(
                            '/companies/{companyId}/opening-balance-imports',
                            {
                                params: { path: { companyId } },
                            },
                        ),
                    )
                ).data;
            } catch (error) {
                if (isApiError(error) && error.status === 404) {
                    return null;
                }

                throw error;
            }
        },
    });

/** The history import of the company, or null before it was imported. */
export const historyImportQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'history-imports'],
        queryFn: async (): Promise<HistoryImport | null> => {
            try {
                return (
                    await unwrap(
                        api.GET('/companies/{companyId}/history-imports', {
                            params: { path: { companyId } },
                        }),
                    )
                ).data;
            } catch (error) {
                if (isApiError(error) && error.status === 404) {
                    return null;
                }

                throw error;
            }
        },
    });

// The generated types of these two answers are bare strings (the controllers return a response
// object, which the generator cannot read); they are `{ data: resource }`.
export async function importOpeningBalances(
    companyId: string,
    body: OpeningBalancesInput,
): Promise<OpeningBatch> {
    const answer = (await unwrap(
        api.POST('/companies/{companyId}/opening-balance-imports', {
            params: { path: { companyId } },
            body,
        }),
    )) as unknown as { data: OpeningBatch };

    return answer.data;
}

export async function importHistory(
    companyId: string,
    body: HistoryInput,
): Promise<HistoryImport> {
    const answer = (await unwrap(
        api.POST('/companies/{companyId}/history-imports', {
            params: { path: { companyId } },
            body,
        }),
    )) as unknown as { data: HistoryImport };

    return answer.data;
}

/** One open item of the source system at the conversion date (SL-FR-10); the party is still the source's reference. */
export type SourceOpenItem = {
    party_ref: string;
    control_role: OpeningOpenItemInput['control_role'];
    kind: OpeningOpenItemInput['kind'];
    document_number: string;
    document_date: string;
    due_date: string | null;
    currency: string | null;
    amount: string;
    exchange_rate: string | null;
};

const ROLES: Record<string, SourceOpenItem['control_role']> = {
    receivables: 'receivables_control',
    receivables_control: 'receivables_control',
    payables: 'payables_control',
    payables_control: 'payables_control',
};

/**
 * Reads `control_role, party, kind, document_number, document_date, amount` and optionally `due_date`,
 * `currency` and `exchange_rate`, one row per open item. `kind` is `invoice` or `credit_note`.
 */
export function parseOpenItems(text: string): {
    items: SourceOpenItem[];
    problems: Problem[];
} {
    // The file is optional: nothing pasted is no open items, not a missing header.
    if (text.trim() === '') {
        return { items: [], problems: [] };
    }

    const rows = parseCsv(text);
    const problems: Problem[] = [];
    const header = rows[0]?.map((name) => name.toLowerCase()) ?? [];
    const index = columns(
        header,
        [
            'control_role',
            'party',
            'kind',
            'document_number',
            'document_date',
            'amount',
        ],
        problems,
    );
    const items: SourceOpenItem[] = [];
    const cell = (row: string[], name: string) =>
        (row[index.get(name) ?? -1] ?? '').trim();

    if (problems.length === 0) {
        rows.slice(1).forEach((row, i) => {
            const line = i + 2;
            const role = ROLES[cell(row, 'control_role').toLowerCase()];
            const kind = cell(row, 'kind').toLowerCase();
            const documentDate = cell(row, 'document_date');
            const dueDate = cell(row, 'due_date');
            const amountText = cell(row, 'amount');
            const rate = cell(row, 'exchange_rate');

            if (role === undefined) {
                problems.push({
                    line,
                    kind: 'role',
                    detail: cell(row, 'control_role'),
                });
            } else if (kind !== 'invoice' && kind !== 'credit_note') {
                problems.push({ line, kind: 'document', detail: kind });
            } else if (cell(row, 'party') === '') {
                problems.push({ line, kind: 'code', detail: '' });
            } else if (cell(row, 'document_number') === '') {
                problems.push({ line, kind: 'document', detail: '' });
            } else if (!ISO_DATE.test(documentDate)) {
                problems.push({ line, kind: 'date', detail: documentDate });
            } else if (dueDate !== '' && !ISO_DATE.test(dueDate)) {
                problems.push({ line, kind: 'date', detail: dueDate });
            } else if (!isDecimal(amountText)) {
                problems.push({ line, kind: 'amount', detail: amountText });
            } else if (rate !== '' && !isDecimal(rate)) {
                problems.push({ line, kind: 'amount', detail: rate });
            } else {
                items.push({
                    party_ref: cell(row, 'party'),
                    control_role: role,
                    kind,
                    document_number: cell(row, 'document_number'),
                    document_date: documentDate,
                    due_date: dueDate || null,
                    currency: cell(row, 'currency') || null,
                    exchange_rate: rate || null,
                    amount: amountText,
                });
            }
        });
    }

    return { items, problems };
}

/** The distinct party references of open items, in order of first appearance. */
export const partyRefsOf = (items: SourceOpenItem[]): string[] => [
    ...new Set(items.map((item) => item.party_ref)),
];

/**
 * What the open items add to each control role, as net debit minus credit in the functional currency,
 * or null when an item is in a foreign currency, whose conversion the server rounds (SL-FR-10).
 */
export function openItemTotals(
    items: SourceOpenItem[],
): Record<SourceOpenItem['control_role'], string> | null {
    if (items.some((item) => item.exchange_rate !== null)) {
        return null;
    }

    const total = (role: SourceOpenItem['control_role']) => {
        const raising = items.filter(
            (item) => item.control_role === role && item.kind === 'invoice',
        );
        const lowering = items.filter(
            (item) => item.control_role === role && item.kind !== 'invoice',
        );
        const gross = sumDecimals(raising.map((item) => item.amount));
        const less = sumDecimals(lowering.map((item) => item.amount));
        const net = differenceDecimals(gross, less);
        // Invoices raise a receivables account on the debit side and a payables account on the credit side.
        const debit =
            role === 'receivables_control'
                ? compareDecimals(gross, less) >= 0
                : compareDecimals(gross, less) <= 0;

        return compareDecimals(net, '0') === 0 ? '0' : debit ? net : `-${net}`;
    };

    return {
        receivables_control: total('receivables_control'),
        payables_control: total('payables_control'),
    };
}

/** A control account whose balance in the file differs from the open items that stand behind it. */
export type ControlMismatch = {
    role: SourceOpenItem['control_role'];
    accountId: string;
    /** Net debit (positive) or credit (negative) of the file's lines on the account. */
    balance: string;
    openItems: string;
};

const sameSigned = (a: string, b: string): boolean => {
    const sign = (value: string) => (value.startsWith('-') ? -1 : 1);
    const magnitude = (value: string) => value.replace(/^-/, '');
    const zero = (value: string) => compareDecimals(magnitude(value), '0') === 0;

    return zero(a) && zero(b)
        ? true
        : sign(a) === sign(b) &&
              compareDecimals(magnitude(a), magnitude(b)) === 0;
};

/**
 * Holds each control account of the file to the total of its open items before sending (SL-FR-10, SL-AC-01);
 * the server decides, this only tells sooner. `roleAccounts` is the account each role maps to.
 */
export function controlMismatches(
    lines: SourceLine[],
    mapping: Record<string, string>,
    roleAccounts: Partial<Record<SourceOpenItem['control_role'], string>>,
    totals: Record<SourceOpenItem['control_role'], string>,
): ControlMismatch[] {
    const mismatches: ControlMismatch[] = [];

    for (const role of Object.keys(totals) as SourceOpenItem['control_role'][]) {
        const accountId = roleAccounts[role];

        if (accountId === undefined) {
            continue;
        }

        const own = lines.filter(
            (line) => mapping[line.source_account_code] === accountId,
        );
        const { debit, credit } = totalsOf(own);
        const net = differenceDecimals(debit, credit);
        const balance =
            compareDecimals(debit, credit) < 0 ? `-${net}` : net;

        if (!sameSigned(balance, totals[role])) {
            mismatches.push({
                role,
                accountId,
                balance,
                openItems: totals[role],
            });
        }
    }

    return mismatches;
}
