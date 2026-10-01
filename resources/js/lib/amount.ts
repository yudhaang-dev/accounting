/**
 * Typing money with separators. The form keeps a canonical decimal string ("1500000.50", API-TR-05);
 * the field shows it the way the reader writes numbers ("1.500.000,50" in Indonesian). These helpers
 * turn what was typed into both, and say where the caret belongs afterwards, because inserting a
 * separator moves every character after it.
 */

export type Separators = { group: string; decimal: string };

export const MAX_DECIMALS = 6;

export function separatorsFor(locale: string): Separators {
    const parts = new Intl.NumberFormat(locale).formatToParts(1234567.5);

    return {
        group: parts.find((part) => part.type === 'group')?.value ?? ',',
        decimal: parts.find((part) => part.type === 'decimal')?.value ?? '.',
    };
}

type Parsed = { text: string; canonical: string };

function compose(
    integer: string,
    fraction: string,
    hasDecimal: boolean,
    separators: Separators,
): Parsed {
    const whole = integer.replace(/^0+(?=\d)/, '');
    const shown = whole === '' && hasDecimal ? '0' : whole;
    const grouped = shown.replace(/\B(?=(\d{3})+(?!\d))/g, separators.group);
    const text = grouped + (hasDecimal ? separators.decimal + fraction : '');
    const canonical =
        shown === '' ? '' : shown + (fraction ? `.${fraction}` : '');

    return { text, canonical };
}

/** A canonical decimal string as shown in the field. */
export function formatCanonical(value: string, separators: Separators): string {
    if (!/^\d+(\.\d+)?$/.test(value)) {
        return '';
    }

    const [integer = '', fraction = ''] = value.split('.');

    return compose(integer, fraction, fraction !== '', separators).text;
}

/**
 * What the field holds after the user typed or pasted `raw` with the caret at `caret`: the grouped
 * text, the canonical value, and the new caret position. Anything but digits and the locale's decimal
 * separator is dropped (so typing a thousands separator by hand does no harm).
 */
export function typeAmount(
    raw: string,
    caret: number,
    separators: Separators,
    maxDecimals = MAX_DECIMALS,
): Parsed & { caret: number } {
    let integer = '';
    let fraction = '';
    let hasDecimal = false;
    // Digits and the decimal separator to the left of the caret: they are what the caret follows.
    let significantBefore = 0;

    // Index by UTF-16 unit, like the `caret` the browser reports; a stray emoji is dropped anyway.
    for (let index = 0; index < raw.length; index += 1) {
        const character = raw.charAt(index);
        const isDigit = character >= '0' && character <= '9';
        const isDecimal = character === separators.decimal && !hasDecimal;

        if (!isDigit && !isDecimal) {
            continue;
        }

        if (index < caret) {
            significantBefore += 1;
        }

        if (isDecimal) {
            hasDecimal = true;
        } else if (hasDecimal) {
            fraction += character;
        } else {
            integer += character;
        }
    }

    fraction = fraction.slice(0, maxDecimals);

    const parsed = compose(integer, fraction, hasDecimal, separators);
    let position = 0;
    let seen = 0;

    for (const character of parsed.text) {
        if (seen >= significantBefore) {
            break;
        }

        position += character.length;

        if (
            (character >= '0' && character <= '9') ||
            character === separators.decimal
        ) {
            seen += 1;
        }
    }

    return { ...parsed, caret: position };
}
