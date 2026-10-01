/**
 * Money and quantities travel as decimal strings (API-TR-05); a JavaScript `number` would round
 * them. These helpers add and compare such strings exactly, using integers scaled by the number of
 * decimal places.
 */

const AMOUNT = /^\d+(\.\d+)?$/;

/** A non-negative decimal as digits, e.g. "12.50" with at most one dot and no sign or spaces. */
export function isDecimal(value: string | null | undefined): value is string {
    return typeof value === 'string' && AMOUNT.test(value.trim());
}

function scaled(value: string, scale: number): bigint {
    const [whole = '0', fraction = ''] = value.trim().split('.');

    return BigInt(whole + fraction.padEnd(scale, '0'));
}

function scaleOf(value: string): number {
    return value.trim().split('.')[1]?.length ?? 0;
}

function toDecimal(total: bigint, scale: number): string {
    if (scale === 0) {
        return total.toString();
    }

    const digits = total.toString().padStart(scale + 1, '0');

    return `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

/** Exact sum of the decimals in the list; anything that is not a decimal counts as zero. */
export function sumDecimals(values: (string | null | undefined)[]): string {
    const valid = values.filter(isDecimal);
    const scale = Math.max(0, ...valid.map(scaleOf));
    const total = valid.reduce((sum, value) => sum + scaled(value, scale), 0n);

    return toDecimal(total, scale);
}

/** Absolute difference of two decimals. */
export function differenceDecimals(a: string, b: string): string {
    const scale = Math.max(scaleOf(a), scaleOf(b));
    const left = scaled(a, scale);
    const right = scaled(b, scale);

    return toDecimal(left > right ? left - right : right - left, scale);
}

/** -1, 0 or 1 as `a` is below, equal to or above `b`. */
export function compareDecimals(a: string, b: string): -1 | 0 | 1 {
    const scale = Math.max(scaleOf(a), scaleOf(b));
    const left = scaled(a, scale);
    const right = scaled(b, scale);

    return left === right ? 0 : left > right ? 1 : -1;
}

/** True when the decimal is greater than zero. */
export function isPositive(value: string | null | undefined): boolean {
    return isDecimal(value) && compareDecimals(value, '0') === 1;
}

/**
 * Groups the digits for the reader's locale without changing the value or its decimal places:
 * `Intl` formats a decimal string exactly, so nothing is rounded through a `number`.
 */
export function formatDecimal(value: string, locale: string): string {
    if (!isDecimal(value)) {
        return value;
    }

    const places = scaleOf(value);

    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: places,
        maximumFractionDigits: places,
    }).format(value as unknown as number);
}
