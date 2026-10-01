import { screen, waitFor, within } from '@testing-library/react';
import { pickDate } from '@/test/pickers';
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
const journalsPath = `/w/${workspace.company_id}/journals`;

const cash = '01JACCOUNT0000000000000001';
const sales = '01JACCOUNT0000000000000002';

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const zero = '0.00';

const report = (overrides: Record<string, unknown> = {}) => ({
    parameters: { from: '2026-01-01', to: '2026-10-02', account_id: null },
    functional_currency: 'IDR',
    balanced: true,
    totals: {
        opening_debit: zero,
        opening_credit: zero,
        debit: '1500000.50',
        credit: '1500000.50',
        closing_debit: '1500000.50',
        closing_credit: '1500000.50',
    },
    rows: [
        {
            account_id: cash,
            code: '1111',
            name: 'Kas Kecil',
            class: 'asset',
            opening_debit: zero,
            opening_credit: zero,
            debit: '1500000.50',
            credit: zero,
            closing_debit: '1500000.50',
            closing_credit: zero,
        },
        {
            account_id: sales,
            code: '4100',
            name: 'Penjualan',
            class: 'income',
            opening_debit: zero,
            opening_credit: zero,
            debit: zero,
            credit: '1500000.50',
            closing_debit: zero,
            closing_credit: '1500000.50',
        },
    ],
    ...overrides,
});

const tbRoutes = (permissions: string[], data = report()) => ({
    ...withPermissions(permissions),
    [`GET ${base}/reports/trial-balance`]: () => json({ data }),
});

describe('trial balance', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows each account with opening, movement and closing, and the totals', async () => {
        mockApi(tbRoutes(['reports.read']));

        renderApp(tbPath);

        expect(await screen.findByText('Kas Kecil')).toBeInTheDocument();
        expect(screen.getByText('Penjualan')).toBeInTheDocument();
        expect(screen.getByText('Seimbang')).toBeInTheDocument();
        expect(screen.getByText('Dalam IDR')).toBeInTheDocument();
        // Cash row: debit movement and closing; the zero sides stay blank.
        const cashRow = screen
            .getByText('Kas Kecil')
            .closest('tr') as HTMLElement;

        expect(within(cashRow).getAllByText('1.500.000,50')).toHaveLength(2);
        expect(within(cashRow).queryByText('0,00')).not.toBeInTheDocument();
        // Totals keep their decimals.
        const total = screen.getByText('Total').closest('tr') as HTMLElement;

        expect(within(total).getAllByText('1.500.000,50')).toHaveLength(4);
        expect(within(total).getAllByText('0,00')).toHaveLength(2);
    });

    it('makes an unbalanced report impossible to miss (RP-AC-01)', async () => {
        mockApi(tbRoutes(['reports.read'], report({ balanced: false })));

        renderApp(tbPath);

        const alert = await screen.findByRole('alert');

        expect(
            within(alert).getByText('Neraca saldo tidak seimbang'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Seimbang')).not.toBeInTheDocument();
    });

    it('says so when nothing moved in the range', async () => {
        mockApi(
            tbRoutes(
                ['reports.read'],
                report({
                    rows: [],
                    totals: { ...report().totals, debit: zero, credit: zero },
                }),
            ),
        );

        renderApp(tbPath);

        expect(
            await screen.findByText('Tidak ada saldo atau mutasi'),
        ).toBeInTheDocument();
    });

    it('asks for the range that was typed, and refuses a backwards one', async () => {
        const api = mockApi(tbRoutes(['reports.read']));

        renderApp(tbPath);
        const typist = userEvent.setup();

        await screen.findByText('Kas Kecil');
        const from = () => screen.getByRole('button', { name: /Dari tanggal/ });
        const to = () => screen.getByRole('button', { name: /Sampai tanggal/ });

        await pickDate(typist, to(), '2026-03-31');
        await pickDate(typist, from(), '2026-02-01');
        await typist.click(screen.getByRole('button', { name: 'Tampilkan' }));

        await waitFor(() =>
            expect(
                api
                    .called(`GET ${base}/reports/trial-balance`)
                    .some(
                        (call) =>
                            call.query.from === '2026-02-01' &&
                            call.query.to === '2026-03-31',
                    ),
            ).toBe(true),
        );

        const before = api.called(`GET ${base}/reports/trial-balance`).length;

        await pickDate(typist, from(), '2026-04-01');
        await typist.click(screen.getByRole('button', { name: 'Tampilkan' }));

        expect(
            await screen.findByText(
                'Tanggal awal tidak boleh setelah tanggal akhir.',
            ),
        ).toBeInTheDocument();
        expect(api.called(`GET ${base}/reports/trial-balance`)).toHaveLength(
            before,
        );
    });

    it('drills down to the journals of an account for the same range', async () => {
        mockApi(tbRoutes(['reports.read', 'journals.read']));

        renderApp(tbPath);

        const link = await screen.findByRole('link', {
            name: 'Lihat jurnal akun 1111',
        });
        const href = new URL(link.getAttribute('href') ?? '', 'http://x');

        expect(href.pathname).toBe(journalsPath);
        expect(href.searchParams.get('account_id')).toBe(cash);
        expect(href.searchParams.get('from')).toMatch(/^\d{4}-01-01$/);
        expect(href.searchParams.get('to')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('exports the same range as CSV with the same permission', async () => {
        let downloaded: string | undefined;

        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                downloaded = this.download;
            },
        );

        const api = mockApi({
            ...tbRoutes(['reports.read']),
            [`GET ${base}/reports/trial-balance`]: (call) =>
                call.accept?.includes('text/csv')
                    ? new Response('code,name\n1111,Kas Kecil\n', {
                          headers: {
                              'Content-Type': 'text/csv; charset=UTF-8',
                              'Content-Disposition':
                                  'attachment; filename="trial-balance_2026-01-01_2026-10-02.csv"',
                          },
                      })
                    : json({ data: report() }),
        });

        renderApp(tbPath);
        const typist = userEvent.setup();

        await screen.findByText('Kas Kecil');
        await typist.click(screen.getByRole('button', { name: 'Ekspor CSV' }));

        await waitFor(() => expect(downloaded).toBeDefined());
        expect(downloaded).toBe('trial-balance_2026-01-01_2026-10-02.csv');

        const csv = api
            .called(`GET ${base}/reports/trial-balance`)
            .find((call) => call.accept?.includes('text/csv'));

        expect(csv?.query.from).toMatch(/^\d{4}-01-01$/);
        expect(csv?.query.to).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('sends someone without reports.read to the company home', async () => {
        mockApi(withPermissions(['entries.read']));

        const { router } = renderApp(tbPath);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });
});

const journal = (
    id: string,
    number: string,
    debitAccount: string,
    creditAccount: string,
) => ({
    id,
    number,
    accounting_date: '2026-10-02',
    period_id: 'p',
    document_date: null,
    description: `Jurnal ${number}`,
    source: 'entry',
    transaction_type_key: 'general',
    transaction_type_version: 1,
    references: null,
    reverses_journal_id: null,
    entry_id: null,
    posted_by: 1,
    posted_at: '2026-10-02T09:00:00+00:00',
    lines: [
        {
            line_number: 1,
            account_id: debitAccount,
            debit: '1500000.50',
            credit: zero,
            currency: 'IDR',
            transaction_amount: '1500000.50',
            exchange_rate: '1',
            dimension_set_id: 'd',
            description: null,
        },
        {
            line_number: 2,
            account_id: creditAccount,
            debit: zero,
            credit: '1500000.50',
            currency: 'IDR',
            transaction_amount: '1500000.50',
            exchange_rate: '1',
            dimension_set_id: 'd',
            description: null,
        },
    ],
});

const accountRow = (id: string, code: string, name: string) => ({
    id,
    code,
    name,
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    lock_version: 1,
});

const jRoutes = (permissions: string[]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/journals`]: () =>
        json({
            data: [
                journal(
                    '01JJOURNAL0000000000000001',
                    'JV-2026-0001',
                    cash,
                    sales,
                ),
            ],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 1 },
        }),
    [`GET ${base}/accounts`]: () =>
        json({
            data: [
                accountRow(cash, '1111', 'Kas Kecil'),
                accountRow(sales, '4100', 'Penjualan'),
            ],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 2 },
        }),
});

describe('journal list', () => {
    it('lists posted journals with their total', async () => {
        mockApi(jRoutes(['journals.read']));

        renderApp(journalsPath);

        expect(await screen.findByText('JV-2026-0001')).toBeInTheDocument();
        expect(screen.getByText('Entri manual')).toBeInTheDocument();
        expect(screen.getByText('1.500.000,50')).toBeInTheDocument();
    });

    it('shows what each journal did to the account being drilled into', async () => {
        const api = mockApi(jRoutes(['journals.read', 'accounts.read']));

        renderApp(
            `${journalsPath}?account_id=${sales}&from=2026-01-01&to=2026-10-02`,
        );

        expect(
            await screen.findByText('Akun: 4100 · Penjualan'),
        ).toBeInTheDocument();
        const row = screen
            .getByText('JV-2026-0001')
            .closest('tr') as HTMLElement;

        // Sales was credited: the debit column stays empty, the credit column has the amount.
        expect(within(row).getAllByText('1.500.000,50')).toHaveLength(1);
        expect(screen.getByText('Kredit akun')).toBeInTheDocument();

        const call = api
            .called(`GET ${base}/journals`)
            .find((c) => c.query['filter[account_id]'] === sales);

        expect(call?.query['filter[from]']).toBe('2026-01-01');
        expect(call?.query['filter[to]']).toBe('2026-10-02');
    });

    it('drops the account filter when it is cleared', async () => {
        const api = mockApi(jRoutes(['journals.read', 'accounts.read']));

        renderApp(`${journalsPath}?account_id=${sales}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Hapus filter akun' }),
        );

        await waitFor(() =>
            expect(
                api
                    .called(`GET ${base}/journals`)
                    .some((call) => !call.query['filter[account_id]']),
            ).toBe(true),
        );
        expect(screen.queryByText(/^Akun:/)).not.toBeInTheDocument();
    });

    it('opens the journal of a row', async () => {
        mockApi({
            ...jRoutes(['journals.read']),
            [`GET ${base}/journals/01JJOURNAL0000000000000001`]: () =>
                json({
                    data: journal(
                        '01JJOURNAL0000000000000001',
                        'JV-2026-0001',
                        cash,
                        sales,
                    ),
                }),
        });

        renderApp(journalsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('link', {
                name: 'Buka jurnal JV-2026-0001',
            }),
        );

        expect(
            await screen.findByRole('heading', { name: 'Jurnal JV-2026-0001' }),
        ).toBeInTheDocument();
    });

    it('goes from a trial balance row to its journals', async () => {
        mockApi({
            ...jRoutes(['journals.read', 'accounts.read', 'reports.read']),
            [`GET ${base}/reports/trial-balance`]: () =>
                json({ data: report() }),
        });

        renderApp(tbPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('link', { name: 'Lihat jurnal akun 4100' }),
        );

        expect(
            await screen.findByText('Akun: 4100 · Penjualan'),
        ).toBeInTheDocument();
        expect(await screen.findByText('JV-2026-0001')).toBeInTheDocument();
    });

    it('says the ledger is empty when no journal was ever posted', async () => {
        mockApi({
            ...withPermissions(['journals.read']),
            [`GET ${base}/journals`]: () =>
                json({
                    data: [],
                    links: {},
                    meta: { current_page: 1, last_page: 1, total: 0 },
                }),
        });

        renderApp(journalsPath);

        expect(await screen.findByText('Belum ada jurnal')).toBeInTheDocument();
    });
});
