import { describe, expect, it, vi } from 'vitest';
import { downloadFile, fileNameFrom } from '@/lib/download';
import { ApiError } from '@/lib/http';

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

describe('a download without a connection', () => {
    it('fails as a network ApiError instead of crashing', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('Failed to fetch');
            }),
        );

        await expect(
            downloadFile('/api/v1/x', 'text/csv', 'x.csv'),
        ).rejects.toMatchObject({
            status: 0,
            code: 'NETWORK',
        });
        await expect(
            downloadFile('/api/v1/x', 'text/csv', 'x.csv'),
        ).rejects.toBeInstanceOf(ApiError);

        vi.unstubAllGlobals();
    });
});
