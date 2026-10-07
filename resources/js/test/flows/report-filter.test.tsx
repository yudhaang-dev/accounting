import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from '@/test/harness';
import {
    filterParams,
    validateFilter,
    emptyFilter,
} from '@/modules/reporting/report-filter';

const base = `/api/v1/companies/${workspace.company_id}`;
const tbPath = `/w/${workspace.company_id}/reports/trial-balance`;
const zero = '0.00';

const page = (data: unknown[]) => ({
    data,
    links: {},
    meta: { current_page: 1, last_page: 1, total: data.length },
});

const branch = '01JDIMTYPE000000000000001A';
const jakarta = '01JDIMVALUE00000000000001A';

const routes = () => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions: ['reports.read'] }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/reports/trial-balance`]: () =>
        json({
            data: {
                parameters: {
                    from: '2026-01-01',
                    to: '2026-10-02',
                    account_id: null,
                },
                functional_currency: 'IDR',
                balanced: true,
                totals: {
                    opening_debit: zero,
                    opening_credit: zero,
                    debit: zero,
                    credit: zero,
                    closing_debit: zero,
                    closing_credit: zero,
                },
                rows: [],
            },
        }),
    [`GET ${base}/dimension-types`]: () =>
        json(
            page([
                {
                    id: branch,
                    key: 'branch',
                    label: 'Cabang',
                    status: 'active',
                },
                {
                    id: '01JDIMTYPE000000000000002A',
                    key: 'project',
                    label: 'Proyek',
                    status: 'active',
                },
            ]),
        ),
    [`GET ${base}/dimension-values`]: () =>
        json(
            page([
                {
                    id: jakarta,
                    dimension_type_id: branch,
                    code: 'JKT',
                    name: 'Jakarta',
                },
            ]),
        ),
    [`GET ${base}/transaction-types`]: () =>
        json({
            data: [
                { key: 'sales', label: 'Penjualan' },
                { key: 'general', label: 'Jurnal umum' },
            ],
        }),
    [`GET ${base}/plan-versions`]: () => json(page([])),
});

describe('report filter', () => {
    afterEach(() => vi.restoreAllMocks());

    it('asks only for the options when the extra filters are opened', async () => {
        const api = mockApi(routes());

        renderApp(tbPath);
        await screen.findByText('Tidak ada saldo atau mutasi');

        expect(api.called(`GET ${base}/dimension-types`)).toHaveLength(0);

        await userEvent.click(
            screen.getByRole('button', { name: /Filter lainnya/ }),
        );

        expect(await screen.findByText('Cabang')).toBeInTheDocument();
        // A type with no value is not offered.
        expect(screen.queryByText('Proyek')).not.toBeInTheDocument();
    });

    it('sends the chosen dimension, Jenis Transaksi keys and source with the range', async () => {
        const api = mockApi(routes());

        renderApp(tbPath);
        const typist = userEvent.setup();

        await screen.findByText('Tidak ada saldo atau mutasi');
        await typist.click(
            screen.getByRole('button', { name: /Filter lainnya/ }),
        );
        await typist.click(
            await screen.findByRole('checkbox', { name: 'Penjualan' }),
        );
        await typist.click(
            screen.getByRole('checkbox', { name: 'Jurnal umum' }),
        );
        await typist.click(screen.getByLabelText('Cabang'));
        await typist.click(await screen.findByText('JKT · Jakarta'));
        await typist.click(screen.getByLabelText('Sumber posting'));
        await typist.click(await screen.findByText('Subledger'));
        await typist.click(screen.getByRole('button', { name: 'Tampilkan' }));

        await waitFor(() => {
            const call = api
                .called(`GET ${base}/reports/trial-balance`)
                .find((c) => c.query.source === 'subledger');

            expect(call).toBeDefined();
            expect(call?.query[`dimensions[branch]`]).toBe(jakarta);
        });

        const url = api
            .called(`GET ${base}/reports/trial-balance`)
            .at(-1) as unknown as { query: Record<string, string> };

        expect(url.query['transaction_type_keys[]']).toBe('general');
    });
});

describe('filter params', () => {
    const filter = {
        ...emptyFilter(),
        from: '2026-01-01',
        to: '2026-03-31',
        dimensions: { branch: jakarta, project: '' },
        transaction_type_keys: ['sales', 'general'],
        source: 'entry' as const,
    };

    it('names only what narrows the report, in the shape the API reads', () => {
        const params = filterParams(filter, 'range');

        expect(params.get('from')).toBe('2026-01-01');
        expect(params.get('dimensions[branch]')).toBe(jakarta);
        expect(params.has('dimensions[project]')).toBe(false);
        expect(params.getAll('transaction_type_keys[]')).toEqual([
            'sales',
            'general',
        ]);
        expect(params.get('source')).toBe('entry');
        expect(params.has('plan_version_id')).toBe(false);
        expect(params.has('as_of')).toBe(false);
    });

    it('uses a single date in as-of mode', () => {
        const params = filterParams(
            { ...filter, as_of: '2026-03-31' },
            'as_of',
        );

        expect(params.get('as_of')).toBe('2026-03-31');
        expect(params.has('from')).toBe(false);
    });

    it('requires dates and their order', () => {
        expect(validateFilter(emptyFilter(), 'range')).toEqual({
            from: 'common.required',
            to: 'common.required',
        });
        expect(
            validateFilter({ ...filter, from: '2026-04-01' }, 'range'),
        ).toEqual({ to: 'reports.filter.rangeInvalid' });
        expect(validateFilter(emptyFilter(), 'as_of')).toEqual({
            as_of: 'common.required',
        });
    });
});
