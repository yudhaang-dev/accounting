import { screen, waitFor, within } from '@testing-library/react';
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
    [`GET ${base}/movement-classifications`]: () =>
        json({
            data: [],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 0 },
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
            screen
                .getByRole('link', { name: 'Lihat buku besar akun 9999' })
                .getAttribute('href'),
        ).toMatch(
            /\/reports\/general-ledger\?account_id=a1&from=2026-01-01&to=2026-10-07/,
        );
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

        const call = api.called(
            `GET ${base}/report-definitions/${defId}/result`,
        )[0];

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

describe('plan comparison', () => {
    afterEach(() => vi.restoreAllMocks());

    const withPlan = (rows: unknown[], planAvailable = true) =>
        routes(
            result({
                columns: [
                    column('actual', 'Realisasi'),
                    column('plan', 'Rencana', {
                        kind: 'plan',
                        available: planAvailable,
                        coverage_status: planAvailable
                            ? 'complete'
                            : 'unavailable',
                    }),
                    column('gap', 'Selisih', {
                        kind: 'difference',
                        from: null,
                        to: null,
                    }),
                ],
                rows,
            }),
            {
                structure: {
                    columns: [{ kind: 'period' }, { kind: 'plan' }],
                },
            },
        );

    it('shows the percentage and the favorable direction of each variance (RP-AC-04)', async () => {
        mockApi(
            withPlan([
                {
                    key: 'cost',
                    label: 'Biaya',
                    type: 'accounts',
                    values: { actual: '80.00', plan: '100.00', gap: '-20.00' },
                    variances: {
                        gap: { percentage: '-20.00', favorable: true },
                    },
                },
                {
                    key: 'sales',
                    label: 'Penjualan',
                    type: 'accounts',
                    values: { actual: '80.00', plan: '100.00', gap: '-20.00' },
                    variances: {
                        gap: { percentage: '-20.00', favorable: false },
                    },
                },
                {
                    key: 'new',
                    label: 'Baru',
                    type: 'accounts',
                    values: { actual: '5.00', plan: '0.00', gap: '5.00' },
                    variances: { gap: { percentage: null, favorable: null } },
                },
            ]),
        );

        renderApp(viewPath);

        const cost = (await screen.findByText('Biaya')).closest(
            'tr',
        ) as HTMLElement;
        const sales = screen.getByText('Penjualan').closest('tr');
        const fresh = screen.getByText('Baru').closest('tr') as HTMLElement;

        expect(within(cost).getByText('-20,00%')).toBeInTheDocument();
        expect(within(cost).getByText('Menguntungkan')).toBeInTheDocument();
        expect(
            within(sales as HTMLElement).getByText('Tidak menguntungkan'),
        ).toBeInTheDocument();
        // A zero plan has no percentage and a zero difference no direction.
        expect(within(fresh).getByText('—')).toBeInTheDocument();
        expect(within(fresh).queryByText(/menguntungkan/i)).toBeNull();
    });

    it('explains a plan column without a version instead of showing zeros', async () => {
        mockApi(
            withPlan(
                [
                    {
                        key: 'cost',
                        label: 'Biaya',
                        type: 'accounts',
                        values: { actual: '80.00', plan: null, gap: null },
                        variances: {
                            gap: { percentage: null, favorable: null },
                        },
                    },
                ],
                false,
            ),
        );

        renderApp(viewPath);

        expect(
            await screen.findByText('Rencana tidak tersedia (Rencana)'),
        ).toBeInTheDocument();
        expect(screen.queryByText('0,00')).not.toBeInTheDocument();
    });
});

describe('report viewer export and drill-down (X-FR-02, API-AC-07)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('exports the same filters as CSV', async () => {
        let downloaded: string | undefined;

        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                downloaded = this.download;
            },
        );

        const api = mockApi({
            ...routes(result()),
            [`GET ${base}/report-definitions/${defId}/result`]: (call) =>
                call.accept?.includes('text/csv')
                    ? new Response('row,label\nsales,Penjualan\n', {
                          headers: {
                              'Content-Type': 'text/csv; charset=UTF-8',
                              'Content-Disposition':
                                  'attachment; filename="report_income-statement_2026-01-01_2026-10-07.csv"',
                          },
                      })
                    : json({ data: result() }),
        });

        renderApp(viewPath);
        const typist = userEvent.setup();

        await screen.findByText('Penjualan');
        await typist.click(screen.getByRole('button', { name: 'Ekspor CSV' }));

        await waitFor(() => expect(downloaded).toBeDefined());
        expect(downloaded).toBe(
            'report_income-statement_2026-01-01_2026-10-07.csv',
        );

        const csv = api
            .called(`GET ${base}/report-definitions/${defId}/result`)
            .find((call) => call.accept?.includes('text/csv'));

        expect(csv?.query.from).toMatch(/^\d{4}-01-01$/);
    });

    it('lists the accounts of a detailed row under it, parents as subtotals and leaves linked to their ledger (RP-AC-12)', async () => {
        mockApi(
            routes(
                result({
                    rows: [
                        {
                            key: 'sales',
                            label: 'Penjualan',
                            type: 'accounts',
                            accounts: [
                                {
                                    account_id: 'a0',
                                    code: '4100',
                                    name: 'Pendapatan Usaha',
                                },
                                {
                                    account_id: 'a1',
                                    code: '4110',
                                    name: 'Penjualan Barang',
                                },
                            ],
                            account_lines: [
                                {
                                    account_id: 'a0',
                                    code: '4100',
                                    name: 'Pendapatan Usaha',
                                    level: 0,
                                    is_leaf: false,
                                    values: { current: '1500000.50' },
                                },
                                {
                                    account_id: 'a1',
                                    code: '4110',
                                    name: 'Penjualan Barang',
                                    level: 1,
                                    is_leaf: true,
                                    values: { current: '1500000.50' },
                                },
                            ],
                            values: { current: '1500000.50' },
                        },
                    ],
                }),
            ),
        );

        renderApp(viewPath);

        const leaf = (await screen.findByText('Penjualan Barang')).closest(
            'tr',
        ) as HTMLElement;
        const parent = screen
            .getByText('Pendapatan Usaha')
            .closest('tr') as HTMLElement;

        expect(within(leaf).getByText('1.500.000,50')).toBeInTheDocument();
        expect(
            within(leaf).getByRole('link', { name: /4110/ }),
        ).toBeInTheDocument();
        expect(within(parent).getByText('1.500.000,50')).toBeInTheDocument();
        expect(within(parent).queryByRole('link')).not.toBeInTheDocument();
        // The accounts are in the rows below, so the row itself does not repeat them as a list.
        expect(screen.queryByText('Buku besar (2)')).not.toBeInTheDocument();
    });

    it('links a row of one account to its ledger and a row of several to a list', async () => {
        mockApi(
            routes(
                result({
                    rows: [
                        {
                            key: 'sales',
                            label: 'Penjualan',
                            type: 'accounts',
                            accounts: [
                                {
                                    account_id: 'a1',
                                    code: '4110',
                                    name: 'Penjualan Barang',
                                },
                            ],
                            values: { current: '10.00' },
                        },
                        {
                            key: 'costs',
                            label: 'Biaya',
                            type: 'accounts',
                            accounts: [
                                {
                                    account_id: 'a2',
                                    code: '6110',
                                    name: 'Gaji',
                                },
                                {
                                    account_id: 'a3',
                                    code: '6220',
                                    name: 'Sewa',
                                },
                            ],
                            values: { current: '5.00' },
                        },
                    ],
                }),
            ),
        );

        renderApp(viewPath);

        const sales = (await screen.findByText('Penjualan')).closest(
            'tr',
        ) as HTMLElement;
        const costs = screen.getByText('Biaya').closest('tr') as HTMLElement;

        expect(
            within(sales)
                .getByRole('link', { name: 'Lihat buku besar akun 4110' })
                .getAttribute('href'),
        ).toMatch(
            /\/reports\/general-ledger\?account_id=a1&from=2026-01-01&to=2026-10-07/,
        );
        expect(within(costs).getByText('Buku besar (2)')).toBeInTheDocument();
        expect(
            within(costs)
                .getByRole('link', { name: 'Lihat buku besar akun 6220' })
                .getAttribute('href'),
        ).toMatch(/account_id=a3/);
    });
});
