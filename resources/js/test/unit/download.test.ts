import { describe, expect, it } from 'vitest';
import { fileNameFrom } from '@/lib/download';

describe('file name of a download', () => {
    it('reads a quoted name', () => {
        expect(
            fileNameFrom(
                'attachment; filename="trial-balance_2026-01-01_2026-10-02.csv"',
            ),
        ).toBe('trial-balance_2026-01-01_2026-10-02.csv');
    });

    it('reads an unquoted name', () => {
        expect(fileNameFrom('attachment; filename=report.csv')).toBe(
            'report.csv',
        );
    });

    it('reads an encoded name', () => {
        expect(
            fileNameFrom("attachment; filename*=UTF-8''neraca%20saldo.csv"),
        ).toBe('neraca saldo.csv');
    });

    it('has none when the header is missing or has no name', () => {
        expect(fileNameFrom(null)).toBeUndefined();
        expect(fileNameFrom('attachment')).toBeUndefined();
    });
});
