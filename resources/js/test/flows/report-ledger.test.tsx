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
const tbPath = `/w/${workspace.company_id}/reports/trial-balance`;
const glPath = `/w/${workspace.company_id}/reports/general-ledger`;
const cash = '01JACCOUNT0000000000000001';
const zero = '0.00';

const page = (data: unknown[]) => ({
    data,
    links: {},
    meta: { current_page: 1, last_page: 1, total: data.length },
});

const coverage = {
    coverage_from: '2026-01-01',
    coverage_to: '2026-10-07',
    coverage_granularity: 'transactions',
    coverage_status: 'complete',
};

const tb = (extra = {}) => ({
    parameters: { from: '2026-01-01', to: '2026-10-07', account_id: null },
    functional_currency: 'IDR',
    restricted: false,
    balanced: true,
    conversion_batch: null,
    totals: {
        opening_debit: zero,
        opening_credit: zero,
        debit: '100.00',
        credit: '100.00',
        closing_debit: '100.00',
        closing_credit: '100.00',
    },
    rows: [
        {
            account_id: cash,
            code: '1111',
            name: 'Kas Kecil',
            class: 'asset',
            opening_debit: zero,
            opening_credit: zero,
            debit: '100.00',
            credit: zero,
            closing_debit: '100.00',
            closing_credit: zero,
        },
    ],
    ...coverage,
    ...extra,
});

const baseRoutes = {
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({
            data: [
                {
                    ...workspace,
                    permissions: [
                        'reports.read',
                        'accounts.read',
                        'journals.read',
                        'entries.read',
                    ],
                },
            ],
        }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/dimension-types`]: () => json(page([])),
    [`GET ${base}/dimension-values`]: () => json(page([])),
    [`GET ${base}/plan-versions`]: () => json(page([])),
    [`GET ${base}/transaction-types`]: () =>
        json({
            data: [
                { key: 'general', label: 'Jurnal umum' },
                { key: 'adjust', label: 'Penyesuaian akhir' },
            ],
        }),
};

const workpaper = {
    adjustment_keys: ['adjust'],
    balanced: true,
    totals: {
        before_debit: '100.00',
        before_credit: '100.00',
        adjustment_debit: '5.00',
        adjustment_credit: '5.00',
        after_debit: '105.00',
        after_credit: '105.00',
    },
    rows: [
        {
            account_id: cash,
            code: '1111',
            name: 'Kas Kecil',
            class: 'asset',
            before_debit: '100.00',
            before_credit: zero,
            adjustment_debit: '5.00',
            adjustment_credit: zero,
            after_debit: '105.00',
            after_credit: zero,
        },
    ],
};

describe('trial balance workpaper and markers', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows before, adjustment and after for the keys chosen (RP-AC-11)', async () => {
        const api = mockApi({
            ...baseRoutes,
            [`GET ${base}/reports/trial-balance`]: (call) =>
                json({
                    data: call.query['adjustment_keys[]']
                        ? tb({ workpaper })
                        : tb(),
                }),
        });

        renderApp(tbPath);
        const typist = userEvent.setup();

        await screen.findByText('Kas Kecil');
        await typist.click(
            screen.getByRole('button', { name: /Filter lainnya/ }),
        );
        const group = await screen.findByRole('group', {
            name: /Kertas kerja/,
        });

        await typist.click(
            within(group).getByRole('checkbox', { name: 'Penyesuaian akhir' }),
        );
        await typist.click(screen.getByRole('button', { name: 'Tampilkan' }));

        expect(
            await screen.findByText('Sebelum penyesuaian'),
        ).toBeInTheDocument();
        expect(screen.getByText('Sesudah penyesuaian')).toBeInTheDocument();

        const cashRow = screen
            .getByText('Kas Kecil')
            .closest('tr') as HTMLElement;

        expect(within(cashRow).getByText('5,00')).toBeInTheDocument();
        expect(within(cashRow).getByText('105,00')).toBeInTheDocument();
        expect(
            api
                .called(`GET ${base}/reports/trial-balance`)
                .some((c) => c.query['adjustment_keys[]'] === 'adjust'),
        ).toBe(true);
    });

    it('marks the conversion batch and a partial history', async () => {
        mockApi({
            ...baseRoutes,
            [`GET ${base}/reports/trial-balance`]: () =>
                json({
                    data: tb({
                        coverage_status: 'partial',
                        coverage_from: '2026-03-01',
                        conversion_batch: {
                            id: 'b',
                            source_key: 'legacy',
                            source_system: 'Accurate',
                            cutover_date: '2026-03-01',
                            ledger_start_date: '2026-03-01',
                            history_granularity: null,
                            journal_id: '01JJOURNAL0000000000000009',
                            journal_number: 'OB-2026-0001',
                        },
                    }),
                }),
        });

        renderApp(tbPath);

        expect(
            await screen.findByText('Memuat saldo konversi'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'OB-2026-0001' }),
        ).toHaveAttribute(
            'href',
            `/w/${workspace.company_id}/journals/01JJOURNAL0000000000000009`,
        );
        expect(
            screen.getByText('Cakupan histori sebagian'),
        ).toBeInTheDocument();
    });

    it('links each account code to its general ledger for the same range', async () => {
        mockApi({
            ...baseRoutes,
            [`GET ${base}/reports/trial-balance`]: () => json({ data: tb() }),
        });

        renderApp(tbPath);

        const link = await screen.findByRole('link', {
            name: 'Lihat buku besar akun 1111',
        });
        const href = new URL(link.getAttribute('href') ?? '', 'http://x');

        expect(href.pathname).toBe(glPath);
        expect(href.searchParams.get('account_id')).toBe(cash);
        expect(href.searchParams.get('from')).toMatch(/^\d{4}-01-01$/);
    });
});

const ledger = (extra = {}) => ({
    parameters: { from: '2026-01-01', to: '2026-10-07', account_id: cash },
    functional_currency: 'IDR',
    restricted: false,
    account: { id: cash, code: '1111', name: 'Kas Kecil' },
    opening_balance: '-50.00',
    lines: [
        {
            journal_id: '01JJOURNAL0000000000000001',
            journal_number: 'JV-2026-0001',
            entry_id: '01JENTRY00000000000000001A',
            accounting_date: '2026-02-01',
            source: 'entry',
            transaction_type_key: 'general',
            source_references: null,
            description: 'Setoran',
            debit: '100.00',
            credit: zero,
            currency: 'USD',
            transaction_amount: '6.50',
            exchange_rate: '15384.6',
            balance: '50.00',
        },
        {
            journal_id: '01JJOURNAL0000000000000002',
            journal_number: 'JV-2026-0002',
            entry_id: null,
            accounting_date: '2026-02-02',
            source: 'system',
            transaction_type_key: null,
            source_references: { invoice: 'INV-7' },
            description: null,
            debit: zero,
            credit: '20.00',
            currency: 'IDR',
            transaction_amount: '20.00',
            exchange_rate: '1',
            balance: '30.00',
        },
    ],
    totals: { debit: '100.00', credit: '20.00' },
    closing_balance: '30.00',
    ...coverage,
    ...extra,
});

describe('general ledger', () => {
    afterEach(() => vi.restoreAllMocks());

    const routes = {
        ...baseRoutes,
        [`GET ${base}/accounts`]: () =>
            json(
                page([
                    {
                        id: cash,
                        code: '1111',
                        name: 'Kas Kecil',
                        class: 'asset',
                        is_leaf: true,
                        status: 'active',
                    },
                ]),
            ),
        [`GET ${base}/reports/general-ledger`]: () => json({ data: ledger() }),
    };

    it('asks for an account before showing anything', async () => {
        const api = mockApi(routes);

        renderApp(glPath);

        expect(
            (await screen.findAllByText('Pilih akun')).length,
        ).toBeGreaterThan(0);
        expect(api.called(`GET ${base}/reports/general-ledger`)).toHaveLength(
            0,
        );
    });

    it('shows the running balance, foreign amounts and links to journal and entry (RP-FR-06)', async () => {
        const api = mockApi(routes);

        renderApp(`${glPath}?account_id=${cash}`);

        const first = (await screen.findByText('Setoran')).closest(
            'tr',
        ) as HTMLElement;

        expect(within(first).getByText('50,00')).toBeInTheDocument();
        expect(
            within(first).getByText('USD 6,50 × 15384.6'),
        ).toBeInTheDocument();
        expect(
            within(first).getByRole('link', { name: 'JV-2026-0001' }),
        ).toHaveAttribute(
            'href',
            `/w/${workspace.company_id}/journals/01JJOURNAL0000000000000001`,
        );
        expect(
            within(first).getByRole('link', { name: 'Lihat entri' }),
        ).toHaveAttribute(
            'href',
            `/w/${workspace.company_id}/entries/01JENTRY00000000000000001A`,
        );

        const second = screen
            .getByText('JV-2026-0002')
            .closest('tr') as HTMLElement;

        expect(within(second).getByText('invoice: INV-7')).toBeInTheDocument();
        expect(within(second).queryByText('Lihat entri')).toBeNull();
        // The opening balance can be negative.
        expect(screen.getByText('-50,00')).toBeInTheDocument();
        expect(
            api.called(`GET ${base}/reports/general-ledger`)[0]?.query
                .account_id,
        ).toBe(cash);
    });

    it('exports the same account and range as CSV', async () => {
        let downloaded: string | undefined;

        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                downloaded = this.download;
            },
        );

        const api = mockApi({
            ...routes,
            [`GET ${base}/reports/general-ledger`]: (call) =>
                call.accept?.includes('text/csv')
                    ? new Response('date,journal\n', {
                          headers: { 'Content-Type': 'text/csv' },
                      })
                    : json({ data: ledger() }),
        });

        renderApp(`${glPath}?account_id=${cash}`);
        const typist = userEvent.setup();

        await screen.findByText('Setoran');
        await typist.click(screen.getByRole('button', { name: 'Ekspor CSV' }));

        await waitFor(() =>
            expect(downloaded).toMatch(/^general-ledger_1111_/),
        );
        expect(
            api
                .called(`GET ${base}/reports/general-ledger`)
                .some(
                    (c) =>
                        c.accept?.includes('text/csv') &&
                        c.query.account_id === cash,
                ),
        ).toBe(true);
    });
});
