import type { Cell, PlanLine } from '@/modules/planning/plans';

/** Amounts of a plan have at most 6 decimals (PL-FR-02). */
const SCALE = 6;

/** A row of the grid: one account with one set of dimension values, across the periods. */
export type GridRow = {
    key: string;
    account_id: string;
    /** Dimension type key => dimension value ID; empty for general activity. */
    dimensions: Record<string, string>;
};

/** Identifies a row regardless of the order the API lists its dimensions in. */
export function rowKey(
    accountId: string,
    dimensions: Record<string, string>,
): string {
    const sorted = Object.entries(dimensions)
        .filter(([, valueId]) => valueId !== '')
        .sort(([a], [b]) => a.localeCompare(b));

    return `${accountId}|${sorted.map(([key, value]) => `${key}=${value}`).join(',')}`;
}

export const cellKey = (row: string, periodId: string) => `${row}#${periodId}`;

/** The dimensions that hold a value; a picker left empty is not a dimension. */
export function cleanDimensions(
    dimensions: Record<string, string>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(dimensions).filter(([, valueId]) => valueId !== ''),
    );
}

/** Rows in the order of the first line of each, with the saved amounts and line ids per cell. */
export function gridOf(lines: PlanLine[]): {
    rows: GridRow[];
    amounts: Map<string, string>;
    lineIds: Map<string, string>;
} {
    const rows = new Map<string, GridRow>();
    const amounts = new Map<string, string>();
    const lineIds = new Map<string, string>();

    for (const line of lines) {
        const dimensions = cleanDimensions(line.dimensions);
        const key = rowKey(line.account_id, dimensions);

        if (!rows.has(key)) {
            rows.set(key, { key, account_id: line.account_id, dimensions });
        }

        amounts.set(cellKey(key, line.period_id), line.amount);
        lineIds.set(cellKey(key, line.period_id), line.id);
    }

    return { rows: [...rows.values()], amounts, lineIds };
}

/** An amount as shown in a cell: no trailing zeros, so `1500000.000000` reads as `1500000`. */
export function trimAmount(value: string): string {
    return value.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : value;
}

function scaled(value: string): bigint {
    const negative = value.trim().startsWith('-');
    const [whole = '0', fraction = ''] = value
        .trim()
        .replace(/^-/, '')
        .split('.');
    const digits = BigInt(whole + fraction.padEnd(SCALE, '0').slice(0, SCALE));

    return negative ? -digits : digits;
}

function plain(total: bigint): string {
    const negative = total < 0n;
    const digits = (negative ? -total : total)
        .toString()
        .padStart(SCALE + 1, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -SCALE)}.${digits.slice(-SCALE)}`;
}

/** Exact sum of amounts that may be negative; empty or malformed ones count as zero. */
export function sumAmounts(values: (string | undefined)[]): string {
    const total = values.reduce(
        (sum, value) =>
            sum +
            (value !== undefined && /^-?\d+(\.\d+)?$/.test(value.trim())
                ? scaled(value)
                : 0n),
        0n,
    );

    return trimAmount(plain(total)) || '0';
}

/** True when the draft text of a cell and the saved amount are the same number. */
export function sameAmount(a: string | undefined, b: string | undefined) {
    return sumAmounts([a]) === sumAmounts([b]);
}

/**
 * What to send to match the grid: the cells whose amount was typed and differs from the saved one,
 * and the lines whose cell was emptied. Nothing is sent for a cell that is empty on both sides.
 */
export function changesOf(
    rows: GridRow[],
    periodIds: string[],
    saved: Map<string, string>,
    lineIds: Map<string, string>,
    draft: Map<string, string>,
): { cells: Cell[]; removed: string[] } {
    const cells: Cell[] = [];
    const removed: string[] = [];

    for (const row of rows) {
        for (const periodId of periodIds) {
            const key = cellKey(row.key, periodId);

            if (!draft.has(key)) {
                continue;
            }

            const typed = draft.get(key) ?? '';
            const before = saved.get(key);

            if (typed === '') {
                const lineId = lineIds.get(key);

                if (lineId !== undefined) {
                    removed.push(lineId);
                }

                continue;
            }

            if (before === undefined || !sameAmount(typed, before)) {
                cells.push({
                    period_id: periodId,
                    account_id: row.account_id,
                    dimensions: row.dimensions,
                    amount: typed,
                });
            }
        }
    }

    return { cells, removed };
}
