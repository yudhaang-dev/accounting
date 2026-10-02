import { useState } from 'react';

/**
 * The value while there is one, and the last value there was once it goes away. A dialog that is
 * opened for "this account" is closed by clearing that value, and it fades out over a moment: during
 * that moment it must still say what it said, not a blank or a default.
 */
export function useLastDefined<T>(value: T | null | undefined): T | undefined {
    const [last, setLast] = useState<T | undefined>(value ?? undefined);

    if (value != null && value !== last) {
        setLast(value);
    }

    return value ?? last;
}
