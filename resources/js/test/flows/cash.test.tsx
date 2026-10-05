import { screen, waitFor, within } from '@testing-library/react';
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
const home = `/w/${workspace.company_id}`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const list = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const bankId = '01JCASH00000000000000000B1';
const boxId = '01JCASH00000000000000000C1';
const ledgerBank = '01JACCOUNT0000000000000011';
const ledgerUsd = '01JACCOUNT0000000000000012';
const ledgerTaken = '01JACCOUNT0000000000000013';
const ledgerEntry = '01JACCOUNT0000000000000014';

const ledgerRow = (overrides: Record<string, unknown>) => ({
    id: ledgerBank,
    code: '1110',
    name: 'Bank BCA',
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    allowed_sources: ['subledger'],
    dimension_requirements: {},
    currency_restriction: 'functional_only',
    restricted_currency: null,
    is_revaluable: false,
    lock_version: 1,
    ...overrides,
});

const chart = [
    ledgerRow({}),
    ledgerRow({
        id: ledgerUsd,
        code: '1120',
        name: 'Bank USD',
        currency_restriction: 'specific',
        restricted_currency: 'USD',
    }),
    ledgerRow({ id: ledgerTaken, code: '1130', name: 'Kas Kecil' }),
    ledgerRow({
        id: ledgerEntry,
        code: '1140',
        name: 'Kas Entri',
        allowed_sources: ['entry', 'subledger'],
    }),
];

const currency = (code: string, functional = false) => ({
    code,
    name: code === 'IDR' ? 'Rupiah' : 'Dolar AS',
    minor_unit: 2,
    scale: 2,
    rounding_mode: 'half_up',
    is_functional: functional,
    status: 'active',
    lock_version: 1,
});

const bank = (overrides: Record<string, unknown> = {}) => ({
    id: bankId,
    name: 'BCA Operasional',
    kind: 'bank',
    bank_name: 'BCA',
    account_number: '1234567890',
    currency: 'IDR',
    account_id: ledgerBank,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const box = (overrides: Record<string, unknown> = {}) => ({
    id: boxId,
    name: 'Kas Kecil',
    kind: 'cash',
    bank_name: null,
    account_number: null,
    currency: 'IDR',
    account_id: ledgerTaken,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const accountRoutes = (permissions: string[], rows = [bank(), box()]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/cash-accounts`]: () => list(rows),
    [`GET ${base}/accounts`]: () => list(chart),
    [`GET ${base}/currencies`]: () =>
        list([currency('IDR', true), currency('USD')]),
});

const manage = ['cash-accounts.read', 'cash-accounts.manage', 'accounts.read'];

describe('cash and bank accounts (SL-FR-03)', () => {
    it('lists the accounts with their bank details, currency and ledger account', async () => {
        mockApi(accountRoutes(manage));

        renderApp(`${home}/cash-accounts`);

        expect(await screen.findByText('BCA Operasional')).toBeInTheDocument();
        expect(screen.getByText('Kas Kecil')).toBeInTheDocument();
        expect(screen.getByText('1234567890')).toBeInTheDocument();
        expect(screen.getByText('1110 · Bank BCA')).toBeInTheDocument();
        expect(screen.getAllByText('IDR')).toHaveLength(2);
    });

    it('adds a bank account on an eligible ledger account only', async () => {
        const create = `POST ${base}/cash-accounts`;
        const api = mockApi({
            ...accountRoutes(manage),
            [create]: () => json({ data: bank({ name: 'BCA USD' }) }, 201),
        });

        renderApp(`${home}/cash-accounts`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah rekening' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'BCA USD');
        await typist.type(within(dialog).getByLabelText('Bank'), 'BCA');
        await typist.type(
            within(dialog).getByLabelText('Nomor rekening'),
            '777',
        );
        await typist.click(within(dialog).getByLabelText('Mata uang'));
        await typist.click(
            await screen.findByRole('option', { name: 'USD · Dolar AS' }),
        );
        await typist.click(within(dialog).getByLabelText('Akun buku besar'));

        // Only the USD-compatible, subledger-only, unused leaf is offered.
        expect(
            await screen.findByRole('option', { name: '1120 · Bank USD' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /1110/ })).toBeNull();
        expect(screen.queryByRole('option', { name: /1130/ })).toBeNull();
        expect(screen.queryByRole('option', { name: /1140/ })).toBeNull();

        await typist.click(
            screen.getByRole('option', { name: '1120 · Bank USD' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'BCA USD',
            kind: 'bank',
            bank_name: 'BCA',
            account_number: '777',
            currency: 'USD',
            account_id: ledgerUsd,
        });
    });

    it('edits only the name and bank details, with the version', async () => {
        const patch = `PATCH ${base}/cash-accounts/${bankId}`;
        const api = mockApi({
            ...accountRoutes(manage),
            [patch]: () => json({ data: bank({ name: 'BCA Utama' }) }),
        });

        renderApp(`${home}/cash-accounts`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Aksi untuk BCA Operasional',
            }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Ubah' }),
        );
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByLabelText('Mata uang')).toBeDisabled();
        expect(within(dialog).getByLabelText('Akun buku besar')).toBeDisabled();

        const name = within(dialog).getByLabelText('Nama');
        await typist.clear(name);
        await typist.type(name, 'BCA Utama');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(patch)).toHaveLength(1));
        expect(api.called(patch)[0]?.body).toEqual({
            lock_version: 1,
            name: 'BCA Utama',
            bank_name: 'BCA',
            account_number: '1234567890',
        });
    });

    it('deactivates an account after confirmation', async () => {
        const deactivate = `POST ${base}/cash-accounts/${boxId}/deactivate`;
        const api = mockApi({
            ...accountRoutes(manage),
            [deactivate]: () => json({ data: box({ status: 'inactive' }) }),
        });

        renderApp(`${home}/cash-accounts`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Aksi untuk Kas Kecil' }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Nonaktifkan' }),
        );
        await typist.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Nonaktifkan',
            }),
        );

        await waitFor(() => expect(api.called(deactivate)).toHaveLength(1));
    });

    it('hides the actions without the manage permission', async () => {
        mockApi(accountRoutes(['cash-accounts.read']));

        renderApp(`${home}/cash-accounts`);

        expect(await screen.findByText('BCA Operasional')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Tambah rekening' }),
        ).toBeNull();
        expect(screen.queryByRole('button', { name: /Aksi untuk/ })).toBeNull();
    });
});

const transaction = (overrides: Record<string, unknown> = {}) => ({
    id: '01JTRX00000000000000000001',
    cash_account_id: bankId,
    direction: 'in',
    amount: '1500000.00',
    currency: 'IDR',
    transaction_date: '2026-09-30',
    reference: 'INV-001',
    document_id: '01JDOC00000000000000000001',
    journal_id: null,
    cleared: false,
    cleared_on: null,
    cleared_by: null,
    ...overrides,
});

const transactionRoutes = (
    permissions: string[],
    rows: ReturnType<typeof transaction>[],
) => ({
    ...withPermissions(permissions),
    [`GET ${base}/cash-accounts`]: () => list([bank(), box()]),
    [`GET ${base}/cash-transactions`]: () => list(rows),
});

const clearer = ['cash-transactions.read', 'cash-transactions.clear'];

describe('cash transactions (SL-FR-11)', () => {
    it('lists transactions with account, direction, amount and cleared state', async () => {
        mockApi(
            transactionRoutes(clearer, [
                transaction(),
                transaction({
                    id: '01JTRX00000000000000000002',
                    cash_account_id: boxId,
                    direction: 'out',
                    amount: '250000.50',
                    reference: null,
                    cleared: true,
                    cleared_on: '2026-10-01',
                }),
            ]),
        );

        renderApp(`${home}/cash-transactions`);

        expect(await screen.findByText('INV-001')).toBeInTheDocument();
        expect(screen.getByText('Penerimaan')).toBeInTheDocument();
        expect(screen.getByText('Pengeluaran')).toBeInTheDocument();
        expect(screen.getByText(/1\.500\.000,00/)).toBeInTheDocument();
        expect(screen.getByText(/250\.000,50/)).toBeInTheDocument();
        expect(screen.getByText('Belum cleared')).toBeInTheDocument();
        expect(screen.getByText(/Cleared 2026-10-01/)).toBeInTheDocument();
    });

    it('sends the filters to the API', async () => {
        const index = `GET ${base}/cash-transactions`;
        const api = mockApi(transactionRoutes(clearer, [transaction()]));
        const typist = userEvent.setup();

        renderApp(`${home}/cash-transactions`);
        await screen.findByText('INV-001');

        await typist.click(screen.getByRole('combobox', { name: 'Rekening' }));
        await typist.click(
            await screen.findByRole('option', { name: 'Kas Kecil (IDR)' }),
        );
        await typist.click(screen.getByRole('combobox', { name: 'Status' }));
        await typist.click(
            await screen.findByRole('option', { name: 'Belum cleared' }),
        );

        await waitFor(() => {
            const last = api.called(index).at(-1);

            expect(last?.query['filter[cash_account_id]']).toBe(boxId);
            expect(last?.query['filter[cleared]']).toBe('0');
        });
    });

    it('marks a transaction cleared on the chosen date', async () => {
        const clear = `POST ${base}/cash-transactions/01JTRX00000000000000000001/clear`;
        const api = mockApi({
            ...transactionRoutes(clearer, [transaction()]),
            [clear]: () =>
                json({
                    data: transaction({
                        cleared: true,
                        cleared_on: '2026-10-01',
                    }),
                }),
        });

        renderApp(`${home}/cash-transactions`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: /Aksi untuk BCA Operasional/,
            }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Tandai cleared' }),
        );
        await typist.click(
            within(await screen.findByRole('dialog')).getByRole('button', {
                name: 'Tandai cleared',
            }),
        );

        await waitFor(() => expect(api.called(clear)).toHaveLength(1));
        expect(api.called(clear)[0]?.body).toEqual({
            cleared_on: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
        });
    });

    it('takes the cleared mark off', async () => {
        const unclear = `POST ${base}/cash-transactions/01JTRX00000000000000000001/unclear`;
        const api = mockApi({
            ...transactionRoutes(clearer, [
                transaction({ cleared: true, cleared_on: '2026-10-01' }),
            ]),
            [unclear]: () => json({ data: transaction() }),
        });

        renderApp(`${home}/cash-transactions`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: /Aksi untuk BCA Operasional/,
            }),
        );
        await typist.click(
            await screen.findByRole('menuitem', {
                name: 'Batalkan tanda cleared',
            }),
        );

        await waitFor(() => expect(api.called(unclear)).toHaveLength(1));
    });

    it('links a transfer to its journal and offers no actions without the clear permission', async () => {
        mockApi(
            transactionRoutes(
                ['cash-transactions.read', 'journals.read'],
                [
                    transaction({
                        document_id: null,
                        journal_id: '01JJOURNAL000000000000001',
                    }),
                ],
            ),
        );

        renderApp(`${home}/cash-transactions`);

        expect(
            await screen.findByRole('link', { name: 'Buka jurnal' }),
        ).toHaveAttribute('href', `${home}/journals/01JJOURNAL000000000000001`);
        expect(screen.queryByRole('button', { name: /Aksi untuk/ })).toBeNull();
    });
});
