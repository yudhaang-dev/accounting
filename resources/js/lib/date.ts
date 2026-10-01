/**
 * Calendar dates travel as `YYYY-MM-DD` strings with no time and no zone. A JavaScript `Date` is an
 * instant, so building one from such a string through `new Date('2026-10-02')` reads it as UTC
 * midnight and can show the day before; these helpers build and read dates in the user's own zone.
 */

const ISO = /^(\d{4})-(\d{2})-(\d{2})$/;

/** The date a `YYYY-MM-DD` string names, or `undefined` when it is not a real date. */
export function parseIsoDate(
    value: string | null | undefined,
): Date | undefined {
    const match = value?.match(ISO);

    if (!match) {
        return undefined;
    }

    const [year, month, day] = [
        Number(match[1]),
        Number(match[2]),
        Number(match[3]),
    ];
    const date = new Date(year, month - 1, day);

    return date.getFullYear() === year &&
        date.getMonth() === month - 1 &&
        date.getDate() === day
        ? date
        : undefined;
}

export function toIsoDate(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Today in the user's zone. */
export function todayIso(): string {
    return toIsoDate(new Date());
}

/** "2 Oktober 2026" / "October 2, 2026", for the reader's locale. */
export function formatIsoDate(value: string, locale: string): string {
    const date = parseIsoDate(value);

    return date
        ? new Intl.DateTimeFormat(locale, {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          }).format(date)
        : value;
}
