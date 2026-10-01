import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from '@/test/harness';

const base = `/api/v1/companies/${workspace.company_id}`;

const cash = '01JACCOUNT0000000000000001';
const sales = '01JACCOUNT0000000000000002';
const journalId = '01JJOURNAL0000000000000001';
const entryId = '01JENTRY00000000000000001';
const journalPath = `/w/${workspace.company_id}/journals/${journalId}`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const line = (n: number, overrides: Record<string, unknown>) => ({
    line_number: n,
    account_id: cash,
    debit: '0.00',
    credit: '0.00',
    currency: 'IDR',
    transaction_amount: '0.00',
    exchange_rate: '1',
    dimension_set_id: '01JDIMSET00000000000000001',
    description: null,
    ...overrides,
});

const journal = (overrides: Record<string, unknown> = {}) => ({
    id: journalId,
    number: 'JV-2026-0001',
    accounting_date: '2026-10-02',
    period_id: '01JPERIOD0000000000000001',
    document_date: null,
    description: 'Setoran modal',
    source: 'entry',
    transaction_type_key: 'general',
    transaction_type_version: 1,
    references: null,
    reverses_journal_id: null,
    entry_id: entryId,
    posted_by: account.id,
    posted_at: '2026-10-02T09:00:00+00:00',
    lines: [
        line(1, { debit: '1500000.50', transaction_amount: '1500000.50' }),
        line(2, {
            account_id: sales,
            credit: '1500000.50',
            transaction_amount: '1500000.50',
            description: 'Modal awal',
        }),
    ],
    ...overrides,
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

const routes = (permissions: string[], current = journal()) => ({
    ...withPermissions(permissions),
    [`GET ${base}/journals/${journalId}`]: () => json({ data: current }),
    [`GET ${base}/accounts`]: () =>
        json({
            data: [
                accountRow(cash, '1111', 'Kas Kecil'),
                accountRow(sales, '4100', 'Penjualan'),
            ],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 2 },
        }),
    [`GET ${base}/periods/01JPERIOD0000000000000001`]: () =>
        json({
            data: {
                id: '01JPERIOD0000000000000001',
                fiscal_year_id: 'fy',
                number: 10,
                name: '2026-10',
                start_date: '2026-10-01',
                end_date: '2026-10-31',
                status: 'open',
            },
        }),
});

const readAll = [
    'journals.read',
    'accounts.read',
    'periods.read',
    'entries.read',
];

describe('journal detail', () => {
    it('shows the header, named lines and exact totals', async () => {
        mockApi(routes(readAll));

        renderApp(journalPath);

        expect(
            await screen.findByRole('heading', { name: 'Jurnal JV-2026-0001' }),
        ).toBeInTheDocument();
        expect(await screen.findByText('1111 · Kas Kecil')).toBeInTheDocument();
        expect(screen.getByText('4100 · Penjualan')).toBeInTheDocument();
        expect(screen.getByText('Modal awal')).toBeInTheDocument();
        expect(await screen.findByText('2026-10')).toBeInTheDocument();
        expect(screen.getByText('Entri manual')).toBeInTheDocument();
        expect(screen.getAllByText('1.500.000,50')).toHaveLength(4);
        expect(
            screen.getByText(/tidak dapat diubah atau dihapus/),
        ).toBeInTheDocument();
        expect(screen.getByText('Anda')).toBeInTheDocument();
    });

    it('links back to the entry it was posted from, when the user may read entries', async () => {
        mockApi(routes(readAll));

        renderApp(journalPath);

        expect(
            await screen.findByRole('link', { name: 'Buka entri' }),
        ).toHaveAttribute(
            'href',
            `/w/${workspace.company_id}/entries/${entryId}`,
        );
    });

    it('does not offer the entry link without the permission, and still shows ids as a fallback', async () => {
        mockApi(routes(['journals.read']));

        renderApp(journalPath);

        await screen.findByRole('heading', { name: 'Jurnal JV-2026-0001' });
        expect(
            screen.queryByRole('link', { name: 'Buka entri' }),
        ).not.toBeInTheDocument();
        // No accounts.read: the lines fall back to the account id rather than failing.
        expect(screen.getByText(cash)).toBeInTheDocument();
    });

    it('links a reversal to the journal it reverses and lists its references', async () => {
        mockApi(
            routes(
                readAll,
                journal({
                    reverses_journal_id: '01JJOURNAL0000000000000000',
                    references: { source_system: 'erp', document: 'INV-7' },
                }),
            ),
        );

        renderApp(journalPath);

        expect(
            await screen.findByRole('link', { name: 'Buka jurnal' }),
        ).toHaveAttribute(
            'href',
            `/w/${workspace.company_id}/journals/01JJOURNAL0000000000000000`,
        );
        expect(screen.getByText('source_system')).toBeInTheDocument();
        expect(screen.getByText('INV-7')).toBeInTheDocument();
    });

    it('shows the rate of a line that is not in the ledger currency', async () => {
        mockApi(
            routes(
                readAll,
                journal({
                    lines: [
                        line(1, {
                            debit: '16000.00',
                            currency: 'USD',
                            transaction_amount: '1.00',
                            exchange_rate: '16000',
                        }),
                        line(2, { account_id: sales, credit: '16000.00' }),
                    ],
                }),
            ),
        );

        renderApp(journalPath);

        expect(await screen.findByText(/USD 1,00/)).toBeInTheDocument();
        expect(screen.getByText(/Kurs 16000/)).toBeInTheDocument();
    });

    it('sends someone without journals.read to the company home', async () => {
        mockApi(withPermissions([]));

        const { router } = renderApp(journalPath);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });

    it('says so when the journal does not exist', async () => {
        mockApi({
            ...withPermissions(readAll),
            [`GET ${base}/journals/${journalId}`]: () =>
                json({ message: 'Not found.', code: 'NOT_FOUND' }, 404),
        });

        renderApp(journalPath);

        expect(
            await screen.findByText('Data tidak ditemukan'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Ke beranda perusahaan' }),
        ).toBeInTheDocument();
    });
});

describe('from an entry to its journal', () => {
    const posted = {
        id: entryId,
        number: 'JU-2026-0001',
        status: 'posted',
        source: 'manual',
        transaction_type_key: 'general',
        transaction_type_version: 1,
        accounting_date: '2026-10-02',
        document_date: null,
        description: 'Setoran modal',
        lines: [
            {
                account_id: cash,
                debit: '1500000.50',
                credit: '0',
                description: null,
            },
            {
                account_id: sales,
                debit: '0',
                credit: '1500000.50',
                description: null,
            },
        ],
        created_by: 1,
        journal_id: journalId,
        journal_number: 'JV-2026-0001',
        posted_at: '2026-10-02T09:00:00+00:00',
        decisions: [],
        lock_version: 3,
    };

    it('opens the journal of a posted entry', async () => {
        mockApi({
            ...routes(readAll),
            [`GET ${base}/entries/${entryId}`]: () => json({ data: posted }),
        });

        renderApp(`/w/${workspace.company_id}/entries/${entryId}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('link', {
                name: 'Diposting sebagai jurnal JV-2026-0001',
            }),
        );

        expect(
            await screen.findByRole('heading', { name: 'Jurnal JV-2026-0001' }),
        ).toBeInTheDocument();
    });

    it('shows the journal number as plain text without journals.read', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/accounts`]: () =>
                json({
                    data: [],
                    links: {},
                    meta: { current_page: 1, last_page: 1, total: 0 },
                }),
            [`GET ${base}/entries/${entryId}`]: () => json({ data: posted }),
        });

        renderApp(`/w/${workspace.company_id}/entries/${entryId}`);

        expect(
            await screen.findByText('Diposting sebagai jurnal JV-2026-0001'),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(
                screen.queryByRole('link', {
                    name: /Diposting sebagai jurnal/,
                }),
            ).not.toBeInTheDocument(),
        );
    });

    it('says so when the entry does not exist', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/entries/${entryId}`]: () =>
                json({ message: 'Not found.', code: 'NOT_FOUND' }, 404),
        });

        renderApp(`/w/${workspace.company_id}/entries/${entryId}`);

        expect(
            await screen.findByText('Data tidak ditemukan'),
        ).toBeInTheDocument();
    });
});
