import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useLastDefined } from '@/hooks/use-last-defined';

describe('last defined value', () => {
    it('is the value while there is one', () => {
        const { result, rerender } = renderHook(
            ({ value }: { value: string | null }) => useLastDefined(value),
            { initialProps: { value: 'a' as string | null } },
        );

        expect(result.current).toBe('a');
        rerender({ value: 'b' });
        expect(result.current).toBe('b');
    });

    it('keeps the last one after it is cleared, so a closing dialog does not go blank', () => {
        const { result, rerender } = renderHook(
            ({ value }: { value: string | null }) => useLastDefined(value),
            { initialProps: { value: 'akun 1111' as string | null } },
        );

        rerender({ value: null });
        expect(result.current).toBe('akun 1111');

        rerender({ value: 'akun 2222' });
        expect(result.current).toBe('akun 2222');
    });

    it('is undefined when there never was a value', () => {
        const { result } = renderHook(() => useLastDefined<string>(null));

        expect(result.current).toBeUndefined();
    });
});
