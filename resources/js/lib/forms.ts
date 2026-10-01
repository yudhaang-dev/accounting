import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import type { ApiError } from '@/lib/http';

/**
 * Puts the server's field errors (422) next to the fields they belong to. `fieldMap` renames
 * API fields that the form calls differently. Returns true when at least one field was marked;
 * otherwise the caller shows the failure as a form-level message.
 */
export function applyApiErrors<T extends FieldValues>(
    setError: UseFormSetError<T>,
    error: ApiError,
    fieldMap: Record<string, Path<T>> = {},
): boolean {
    let applied = false;

    for (const [field, messages] of Object.entries(error.errors)) {
        const message = messages[0];

        if (message === undefined) {
            continue;
        }

        setError((fieldMap[field] ?? field) as Path<T>, {
            type: 'server',
            message,
        });
        applied = true;
    }

    return applied;
}
