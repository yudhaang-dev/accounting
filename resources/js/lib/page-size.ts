import { useState } from 'react';

/** Page sizes every list offers; `ALL` (0) shows every row and only suits lists held in the browser. */
export const PAGE_SIZES = [10, 25, 50, 100] as const;
export const ALL = 0;

const key = (scope: string) => `accounting.pageSize.${scope}`;

function read(scope: string, allowed: number[], fallback: number): number {
    try {
        const stored = Number(window.localStorage.getItem(key(scope)));

        return allowed.includes(stored) ? stored : fallback;
    } catch {
        return fallback;
    }
}

/**
 * How many rows a list shows per page, remembered per list in this browser so the choice survives
 * a reload. Storage may be blocked or empty; the list then simply uses its default.
 */
export function usePageSize(
    scope: string,
    { fallback = 25, allowAll = false } = {},
): [number, (size: number) => void] {
    const allowed: number[] = allowAll ? [...PAGE_SIZES, ALL] : [...PAGE_SIZES];
    const [size, setSize] = useState(() => read(scope, allowed, fallback));

    return [
        size,
        (next) => {
            setSize(next);

            try {
                window.localStorage.setItem(key(scope), String(next));
            } catch {
                // The choice just will not be remembered.
            }
        },
    ];
}
