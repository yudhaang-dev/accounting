import { screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from '@/test/harness';

const base = `/api/v1/companies/${workspace.company_id}`;
const listPath = `/w/${workspace.company_id}/reports`;
const defId = '01JREPORTDEF0000000000001A';
const viewPath = `${listPath}/${defId}`;

const definition = (overrides: Record<string, unknown> = {}) => ({
    id: defId,
    key: 'income-statement',
    name: 'Laba Rugi',
    kind: 'income_statement',
    current_version: 3,
    lock_version: 1,
    version: 3,
    structure: { basis: 'movement', rows: [], columns: [] },
    ...overrides,
});

const column = (key: string, label: string, extra = {}) => ({
    key,
    label,
    kind: 'period',
    from: '2026-01-01',
    to: '2026-10-07',
    available: true,
    coverage_status: 'complete',
    ...extra,
});

const result = (overrides: Record<string, unknown> = {}) => ({
    definition: { id: defId, key: 'income-statement', version: 3 },
    parameters: { from: '2026-01-01', to: '2026-10-07' },
    functional_currency: 'IDR',
    restricted: false,
    columns: [column('current', 'Tahun ini')],
    rows: [
        { key: 'income', label: 'Pendapatan', type: 'heading', values: {} },
        {
            key: 'sales',
            label: 'Penjualan',
            type: 'accounts',
            values: { current: '1500000.50' },
        },
        {
            key: 'profit',
            label: 'Laba bersih',
            type: 'subtotal',
            values: { current: '-250000.00' },
        },
    ],
    unmapped_accounts: [],
    coverage_from: '2026-01-01',
    coverage_to: '2026-10-07',
    coverage_granularity: 'transactions',
    coverage_status: 'complete',
    ...overrides,
});

const routes = (
    data: Record<string, unknown>,
    def: Record<string, unknown> = {},
    permissions = ['reports.read'],
) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/report-definitions`]: () =>
        json({
            data: [definition(def)],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 1 },
        }),
    [`GET ${base}/report-definitions/${defId}`]: () =>
        json({ data: definition(def) }),
    [`GET ${base}/report-definitions/${defId}/result`]: () => json({ data }),
});

describe('report list', () => {
    afterEach(() => vi.restoreAllMocks());

    it('lists the definitions and opens one in the viewer', async () => {
        mockApi(routes(result()));

        renderApp(listPath);

        const link = await screen.findByRole('link', { name: 'Laba Rugi' });

        expect(link).toHaveAttribute('href', viewPath);
        expect(screen.getByText('income-statement')).toBeInTheDocument();
        expect(screen.getByText('Laba rugi')).toBeInTheDocument();
    });
});

describe('report viewer', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows a value per column, keeps negatives, and leaves headings empty', async () => {
        mockApi(routes(result()));

        renderApp(viewPath);

        expect(
            await screen.findByRole('heading', { name: 'Laba Rugi' }),
        ).toBeInTheDocument();
        const sales = (await screen.findByText('Penjualan')).closest(
            'tr',
        ) as HTMLElement;
        const profit = screen.getByText('Laba bersih').closest('tr');

        expect(within(sales).getByText('1.500.000,50')).toBeInTheDocument();
        expect(
            within(profit as HTMLElement).getByText('-250.000,00'),
        ).toBeInTheDocument();
        expect(screen.getByText('Dalam IDR')).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('shows a column the ledger cannot answer for as unavailable, never zero (RP-AC-10)', async () => {
        mockApi(
            routes(
                result({
                    columns: [
                        column('current', 'Tahun ini'),
                        column('previous', 'Tahun lalu', {
                            available: false,
                            coverage_status: 'unavailable',
                        }),
                    ],
                    rows: [
                        {
                            key: 'sales',
                            label: 'Penjualan',
                            type: 'accounts',
                            values: { current: '10.00', previous: null },
                        },
                    ],
                    coverage_status: 'partial',
                    coverage_from: '2026-03-01',
                }),
            ),
        );

        renderApp(viewPath);

        const row = (await screen.findByText('Penjualan')).closest(
            'tr',
        ) as HTMLElement;

        expect(within(row).getByText('—')).toBeInTheDocument();
        expect(within(row).queryByText('0,00')).not.toBeInTheDocument();
        expect(screen.getByText('Tidak tersedia')).toBeInTheDocument();
        expect(
            screen.getByText('Cakupan histori sebagian'),
        ).toBeInTheDocument();
    });

    it('calls out accounts no row claims and a data scope restriction', async () => {
        mockApi(
            routes(
                result({
                    restricted: true,
                    rows: [
                        {
                            key: 'unmapped',
                            label: 'Belum dipetakan',
                            type: 'unmapped',
                            values: { current: '5.00' },
                        },
                    ],
                    unmapped_accounts: [
                        {
                            account_id: 'a1',
                            code: '9999',
                            name: 'Akun Baru',
                        },
                    ],
                }),
            ),
        );

        renderApp(viewPath);

        expect(
            await screen.findByText('Akun yang belum dipetakan'),
        ).toBeInTheDocument();
        expect(screen.getByText('Akun Baru', { exact: false })).toBeVisible();
        expect(
            screen.getByText('Dibatasi cakupan data Anda'),
        ).toBeInTheDocument();
    });

    it('runs a balance sheet for one date and keeps the balance check in view', async () => {
        const api = mockApi(
            routes(
                result({
                    columns: [
                        column('now', 'Saldo akhir', {
                            kind: 'as_of',
                            from: null,
                            to: '2026-10-07',
                        }),
                    ],
                    balance_checks: {
                        now: {
                            assets: '100.00',
                            liabilities_and_equity: '90.00',
                            difference: '10.00',
                            balanced: false,
                        },
                    },
                }),
                { kind: 'balance_sheet', name: 'Neraca' },
            ),
        );

        renderApp(viewPath);

        const alert = await screen.findByRole('alert');

        expect(
            within(alert).getByText('Neraca tidak seimbang (Saldo akhir)'),
        ).toBeInTheDocument();
        expect(within(alert).getByText(/selisih 10,00/)).toBeInTheDocument();

        const call = api.called(`GET ${base}/report-definitions/${defId}/result`)[0];

        expect(call?.query.as_of).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(call?.query.from).toBeUndefined();
    });

    it('shows a balanced sheet quietly and a cash flow gap as an alert', async () => {
        mockApi(
            routes(
                result({
                    balance_checks: {
                        current: {
                            assets: '1.00',
                            liabilities_and_equity: '1.00',
                            difference: '0.00',
                            balanced: true,
                        },
                    },
                    cash_checks: {
                        current: {
                            opening_balance: '1.00',
                            net_increase: '2.00',
                            closing_balance: '3.00',
                            reconciled: false,
                        },
                    },
                }),
            ),
        );

        renderApp(viewPath);

        expect(
            await screen.findByText('Aset sama dengan liabilitas dan ekuitas'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Arus kas tidak cocok dengan kas (Tahun ini)'),
        ).toBeInTheDocument();
    });

    it('says so when the definition does not exist', async () => {
        mockApi({
            ...routes(result()),
            [`GET ${base}/report-definitions/${defId}`]: () =>
                json({ message: 'Not found', code: 'NOT_FOUND' }, 404),
        });

        renderApp(viewPath);

        expect(
            await screen.findByText('Data tidak ditemukan'),
        ).toBeInTheDocument();
    });

    it('sends someone without reports.read to the company home', async () => {
        mockApi(routes(result(), {}, ['entries.read']));

        const { router } = renderApp(viewPath);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });
});
