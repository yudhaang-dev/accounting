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

// Bank reconciliation by statement import (SL-FR-13): statements, the column mapping, matching, the report.

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
const statementId = '01JSTMT00000000000000000S1';
const lineIn = '01JLINE0000000000000000001';
const lineOut = '01JLINE0000000000000000002';
const bookId = '01JTRX00000000000000000001';

const bank = {
    id: bankId,
    name: 'BCA Operasional',
    kind: 'bank',
    bank_name: 'BCA',
    account_number: '1234567890',
    currency: 'IDR',
    account_id: '01JACCOUNT0000000000000011',
    status: 'active',
    lock_version: 1,
};

const statement = (overrides: Record<string, unknown> = {}) => ({
    id: statementId,
    cash_account_id: bankId,
    file_name: 'maret.csv',
    period_from: '2026-03-10',
    period_to: '2026-03-15',
    closing_date: '2026-03-15',
    closing_balance: '2485000.000000',
    line_count: 2,
    matched_count: 1,
    imported_by: 1,
    created_at: '2026-03-16T01:00:00+00:00',
    ...overrides,
});

const line = (overrides: Record<string, unknown> = {}) => ({
    id: lineIn,
    statement_id: statementId,
    cash_account_id: bankId,
    line_number: 2,
    transaction_date: '2026-03-10',
    description: 'Transfer PT Maju',
    reference: 'INV-001',
    direction: 'in',
    amount: '1500000.000000',
    cash_transaction_id: null,
    matched_at: null,
    matched_by: null,
    ...overrides,
});

const mapping = (overrides: Record<string, unknown> = {}) => ({
    id: '01JMAP00000000000000000001',
    cash_account_id: bankId,
    delimiter: ',',
    date_format: 'Y-m-d',
    decimal_separator: '.',
    has_header: true,
    date_column: 1,
    description_column: 2,
    reference_column: 3,
    amount_column: 4,
    money_in_column: null,
    money_out_column: null,
    lock_version: 1,
    ...overrides,
});

const report = (overrides: Record<string, unknown> = {}) => ({
    statement_id: statementId,
    cash_account_id: bankId,
    currency: 'IDR',
    closing_date: '2026-03-15',
    statement_balance: '2485000.000000',
    book_balance: '2400000.000000',
    outstanding_in: '0.000000',
    outstanding_out: '100000.000000',
    unrecorded_in: '10000.000000',
    unrecorded_out: '25000.000000',
    adjusted_statement_balance: '2385000.000000',
    adjusted_book_balance: '2385000.000000',
    difference: '0.000000',
    reconciled: true,
    outstanding_transactions: [
        {
            cash_transaction_id: '01JTRX00000000000000000009',
            transaction_date: '2026-03-14',
            direction: 'out',
            amount: '100000.000000',
            reference: 'CHQ-9',
        },
    ],
    unrecorded_lines: [
        {
            line_id: lineOut,
            statement_id: statementId,
            line_number: 3,
            transaction_date: '2026-03-12',
            direction: 'out',
            amount: '25000.000000',
            description: 'Biaya admin',
            reference: null,
        },
    ],
    ...overrides,
});

const book = (overrides: Record<string, unknown> = {}) => ({
    id: bookId,
    cash_account_id: bankId,
    direction: 'in',
    amount: '1500000.000000',
    currency: 'IDR',
    transaction_date: '2026-03-09',
    reference: 'INV-001',
    document_id: null,
    journal_id: null,
    cleared: false,
    cleared_on: null,
    cleared_by: null,
    ...overrides,
});

const reader = ['bank-statements.read'];
const manager = [
    'bank-statements.read',
    'bank-statements.manage',
    'cash-transactions.read',
];

const listRoutes = (permissions: string[], rows = [statement()]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/cash-accounts`]: () => list([bank]),
    [`GET ${base}/bank-statements`]: () => list(rows),
});

const detailRoutes = (
    permissions: string[],
    lines = [
        line(),
        line({
            id: lineOut,
            line_number: 3,
            direction: 'out',
            amount: '25000.000000',
            description: 'Biaya admin',
            reference: null,
        }),
    ],
) => ({
    ...withPermissions(permissions),
    [`GET ${base}/cash-accounts`]: () => list([bank]),
    [`GET ${base}/bank-statements/${statementId}`]: () =>
        json({ data: statement() }),
    [`GET ${base}/bank-statements/${statementId}/report`]: () =>
        json({ data: report() }),
    [`GET ${base}/bank-statements/${statementId}/lines`]: () => list(lines),
});

describe('the list of statements', () => {
    it('shows each statement with its account, period, balance and matched lines', async () => {
        mockApi(listRoutes(reader));

        renderApp(`${home}/bank-statements`);

        expect(
            await screen.findByRole('link', { name: 'BCA Operasional' }),
        ).toBeInTheDocument();
        expect(screen.getByText('maret.csv')).toBeInTheDocument();
        expect(screen.getByText(/2026-03-10 – 2026-03-15/)).toBeInTheDocument();
        expect(screen.getByText(/2\.485\.000,00/)).toBeInTheDocument();
        expect(screen.getByText('1 / 2')).toBeInTheDocument();
    });

    it('keeps import and mapping to those who manage them', async () => {
        mockApi(listRoutes(reader));

        renderApp(`${home}/bank-statements`);
        await screen.findByText('maret.csv');

        expect(
            screen.queryByRole('button', { name: 'Impor rekening koran' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Pemetaan kolom' }),
        ).toBeNull();
    });

    it('sends the account filter to the API', async () => {
        const index = `GET ${base}/bank-statements`;
        const api = mockApi(listRoutes(reader));
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements`);
        await screen.findByText('maret.csv');

        await typist.click(screen.getByRole('combobox', { name: 'Rekening' }));
        await typist.click(
            await screen.findByRole('option', {
                name: 'BCA Operasional (IDR)',
            }),
        );

        await waitFor(() =>
            expect(
                api.called(index).at(-1)?.query['filter[cash_account_id]'],
            ).toBe(bankId),
        );
    });
});

describe('importing a statement', () => {
    const importRoutes = (mappingReply: () => Response) => ({
        ...listRoutes(manager),
        [`GET ${base}/cash-accounts/${bankId}/import-mapping`]: mappingReply,
    });

    const openDialog = async (typist: ReturnType<typeof userEvent.setup>) => {
        await screen.findByText('maret.csv');
        await typist.click(
            screen.getByRole('button', { name: 'Impor rekening koran' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('combobox', { name: 'Rekening' }),
        );
        await typist.click(
            await screen.findByRole('option', {
                name: 'BCA Operasional (IDR)',
            }),
        );

        return dialog;
    };

    it('sends the file text with the closing balance and opens the statement', async () => {
        const store = `POST ${base}/cash-accounts/${bankId}/bank-statements`;
        const api = mockApi({
            ...importRoutes(() => json({ data: mapping() })),
            [store]: () => json({ data: statement() }, 201),
            ...detailRoutes(manager),
            [`GET ${base}/bank-statements`]: () => list([statement()]),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements`);
        const dialog = await openDialog(typist);

        await typist.upload(
            within(dialog).getByLabelText('Berkas CSV'),
            new File(
                [
                    'date,description,reference,amount\n2026-03-10,Setoran,,100\n',
                ],
                'maret.csv',
                { type: 'text/csv' },
            ),
        );
        await typist.type(
            within(dialog).getByLabelText('Saldo akhir'),
            '2485000',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor' }),
        );

        await waitFor(() => expect(api.called(store)).toHaveLength(1));
        expect(api.called(store)[0]?.body).toEqual({
            csv: 'date,description,reference,amount\n2026-03-10,Setoran,,100\n',
            file_name: 'maret.csv',
            closing_balance: '2485000',
            closing_date: null,
        });
        expect(await screen.findByText('Rekonsiliasi')).toBeInTheDocument();
    });

    it('lists the lines the API refused and keeps the dialog open', async () => {
        const store = `POST ${base}/cash-accounts/${bankId}/bank-statements`;
        mockApi({
            ...importRoutes(() => json({ data: mapping() })),
            [store]: () =>
                json(
                    {
                        message: 'invalid',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'rows.3.date': [
                                'Tanggal "2026-13-45" tidak sesuai format Y-m-d.',
                            ],
                            'rows.4.amount': [
                                'Jumlah "abc" bukan angka selain nol.',
                            ],
                        },
                    },
                    422,
                ),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements`);
        const dialog = await openDialog(typist);

        await typist.upload(
            within(dialog).getByLabelText('Berkas CSV'),
            new File(['x'], 'bad.csv', { type: 'text/csv' }),
        );
        await typist.type(within(dialog).getByLabelText('Saldo akhir'), '1');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor' }),
        );

        expect(
            await within(dialog).findByText(/Berkas tidak diimpor/),
        ).toBeInTheDocument();
        expect(within(dialog).getByText(/Baris 3:/)).toBeInTheDocument();
        expect(
            within(dialog).getByText(/tidak sesuai format/),
        ).toBeInTheDocument();
        expect(within(dialog).getByText(/Baris 4:/)).toBeInTheDocument();
    });

    it('asks for the column mapping first when the account has none, and saves it', async () => {
        const save = `PUT ${base}/cash-accounts/${bankId}/import-mapping`;
        const api = mockApi({
            ...importRoutes(() => json({ data: null })),
            [save]: () => json({ data: mapping() }, 201),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements`);
        const dialog = await openDialog(typist);

        expect(
            await within(dialog).findByText(/belum punya pemetaan kolom/),
        ).toBeInTheDocument();
        await typist.click(
            within(dialog).getByRole('button', { name: 'Atur pemetaan kolom' }),
        );

        const mappingDialog = await screen.findByRole('dialog', {
            name: 'Pemetaan kolom',
        });

        await typist.click(
            within(mappingDialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(save)).toHaveLength(1));
        expect(api.called(save)[0]?.body).toEqual({
            lock_version: null,
            delimiter: ',',
            date_format: 'Y-m-d',
            decimal_separator: '.',
            has_header: true,
            date_column: 1,
            description_column: 2,
            reference_column: 3,
            amount_column: 4,
            money_in_column: null,
            money_out_column: null,
        });
    });
});

describe('column mapping', () => {
    it('saves separate money in and money out columns with the version that was read', async () => {
        const save = `PUT ${base}/cash-accounts/${bankId}/import-mapping`;
        const api = mockApi({
            ...listRoutes(manager),
            [`GET ${base}/cash-accounts/${bankId}/import-mapping`]: () =>
                json({ data: mapping() }),
            [save]: () => json({ data: mapping({ lock_version: 2 }) }),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements`);
        await screen.findByText('maret.csv');
        await typist.click(screen.getByRole('combobox', { name: 'Rekening' }));
        await typist.click(
            await screen.findByRole('option', {
                name: 'BCA Operasional (IDR)',
            }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Pemetaan kolom' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Pemetaan kolom',
        });

        await typist.click(
            await within(dialog).findByRole('combobox', { name: 'Jumlah' }),
        );
        await typist.click(
            await screen.findByRole('option', {
                name: 'Kolom uang masuk dan uang keluar terpisah',
            }),
        );
        await typist.type(
            within(dialog).getByLabelText('Kolom uang masuk'),
            '4',
        );
        await typist.type(
            within(dialog).getByLabelText('Kolom uang keluar'),
            '5',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(save)).toHaveLength(1));
        expect(api.called(save)[0]?.body).toMatchObject({
            lock_version: 1,
            amount_column: null,
            money_in_column: 4,
            money_out_column: 5,
        });
    });

    it('refuses a column that is not a number from 1 to 50', async () => {
        const save = `PUT ${base}/cash-accounts/${bankId}/import-mapping`;
        const api = mockApi({
            ...listRoutes(manager),
            [`GET ${base}/cash-accounts/${bankId}/import-mapping`]: () =>
                json({ data: mapping() }),
            [save]: () => json({ data: mapping() }),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements`);
        await screen.findByText('maret.csv');
        await typist.click(screen.getByRole('combobox', { name: 'Rekening' }));
        await typist.click(
            await screen.findByRole('option', {
                name: 'BCA Operasional (IDR)',
            }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Pemetaan kolom' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Pemetaan kolom',
        });
        const date = await within(dialog).findByLabelText('Kolom tanggal');

        await typist.clear(date);
        await typist.type(date, '0');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText('Isi nomor kolom 1 sampai 50.'),
        ).toBeInTheDocument();
        expect(api.called(save)).toHaveLength(0);
    });
});

describe('a statement', () => {
    it('shows the reconciliation with what is outstanding on each side', async () => {
        mockApi(detailRoutes(reader));

        renderApp(`${home}/bank-statements/${statementId}`);

        expect(await screen.findByText('Sudah cocok')).toBeInTheDocument();
        expect(
            screen.getByText(/Saldo rekening koran per 2026-03-15/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Saldo buku per 2026-03-15/),
        ).toBeInTheDocument();
        expect(screen.getAllByText(/2\.385\.000,00/)).toHaveLength(2);
        expect(screen.getByText('CHQ-9')).toBeInTheDocument();
        expect(screen.getAllByText('Biaya admin').length).toBeGreaterThan(0);
    });

    it('shows the difference that remains', async () => {
        mockApi({
            ...detailRoutes(reader),
            [`GET ${base}/bank-statements/${statementId}/report`]: () =>
                json({
                    data: report({ difference: '5.000000', reconciled: false }),
                }),
        });

        renderApp(`${home}/bank-statements/${statementId}`);

        expect(await screen.findByText(/Selisih 5,00 IDR/)).toBeInTheDocument();
    });

    it('lists the lines and hides matching from a reader', async () => {
        mockApi(detailRoutes(reader));

        renderApp(`${home}/bank-statements/${statementId}`);

        expect(await screen.findByText('Transfer PT Maju')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Cocokkan baris/ }),
        ).toBeNull();
    });

    it('filters the lines by matched state', async () => {
        const lines = `GET ${base}/bank-statements/${statementId}/lines`;
        const api = mockApi(detailRoutes(reader));
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements/${statementId}`);
        await screen.findByText('Transfer PT Maju');

        await typist.click(screen.getByRole('combobox', { name: 'Status' }));
        await typist.click(
            await screen.findByRole('option', { name: 'Belum cocok' }),
        );

        await waitFor(() =>
            expect(api.called(lines).at(-1)?.query['filter[matched]']).toBe(
                '0',
            ),
        );
    });

    it('matches a line with the transaction it suggests first', async () => {
        const suggestions = `GET ${base}/bank-statements/${statementId}/suggestions`;
        const match = `POST ${base}/bank-statement-lines/${lineIn}/match`;
        const other = book({
            id: '01JTRX00000000000000000002',
            reference: 'LAIN',
            transaction_date: '2026-03-10',
        });
        const api = mockApi({
            ...detailRoutes(manager),
            [suggestions]: () =>
                json({
                    data: [
                        {
                            line_id: lineIn,
                            candidates: [
                                {
                                    cash_transaction_id: bookId,
                                    score: 101,
                                    date_difference_days: 1,
                                    reference_match: true,
                                },
                            ],
                        },
                    ],
                }),
            [`GET ${base}/cash-transactions`]: () =>
                list([
                    other,
                    book(),
                    book({
                        id: '01JTRX00000000000000000003',
                        direction: 'out',
                    }),
                    book({
                        id: '01JTRX00000000000000000004',
                        amount: '10.000000',
                    }),
                ]),
            [match]: () =>
                json({ data: line({ cash_transaction_id: bookId }) }),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements/${statementId}`);
        await typist.click(
            await screen.findByRole('button', { name: 'Cocokkan baris 2' }),
        );

        const dialog = await screen.findByRole('dialog');
        const rows = await within(dialog).findAllByRole('row');

        // The header and the two transactions of the same direction and amount, the suggested one first.
        expect(rows).toHaveLength(3);
        expect(within(rows[1]!).getByText('INV-001')).toBeInTheDocument();
        expect(
            within(rows[1]!).getByText('Disarankan, referensi sama'),
        ).toBeInTheDocument();
        expect(within(rows[2]!).getByText('LAIN')).toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('button', {
                name: 'Cocokkan dengan INV-001 tanggal 2026-03-09',
            }),
        );

        await waitFor(() => expect(api.called(match)).toHaveLength(1));
        expect(api.called(match)[0]?.body).toEqual({
            cash_transaction_id: bookId,
        });
    });

    it('says so when no transaction has the same amount', async () => {
        mockApi({
            ...detailRoutes(manager),
            [`GET ${base}/bank-statements/${statementId}/suggestions`]: () =>
                json({ data: [] }),
            [`GET ${base}/cash-transactions`]: () =>
                list([book({ amount: '10.000000' })]),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements/${statementId}`);
        await typist.click(
            await screen.findByRole('button', { name: 'Cocokkan baris 2' }),
        );

        expect(
            await within(await screen.findByRole('dialog')).findByText(
                /Tidak ada transaksi kas pada rekening ini/,
            ),
        ).toBeInTheDocument();
    });

    it('shows what the API said when a match is refused', async () => {
        const match = `POST ${base}/bank-statement-lines/${lineIn}/match`;
        mockApi({
            ...detailRoutes(manager),
            [`GET ${base}/bank-statements/${statementId}/suggestions`]: () =>
                json({ data: [] }),
            [`GET ${base}/cash-transactions`]: () => list([book()]),
            [match]: () =>
                json(
                    {
                        message:
                            'Transaksi kas sudah dicocokkan dengan baris mutasi lain.',
                        code: 'INVALID_STATE_TRANSITION',
                        errors: {
                            cash_transaction_id: [
                                'Transaksi kas sudah dicocokkan dengan baris mutasi lain.',
                            ],
                        },
                    },
                    409,
                ),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements/${statementId}`);
        await typist.click(
            await screen.findByRole('button', { name: 'Cocokkan baris 2' }),
        );
        await typist.click(
            await screen.findByRole('button', {
                name: /Cocokkan dengan INV-001/,
            }),
        );

        expect(
            await screen.findByText(
                /sudah dicocokkan dengan baris mutasi lain/,
            ),
        ).toBeInTheDocument();
    });

    it('takes a match back', async () => {
        const unmatch = `POST ${base}/bank-statement-lines/${lineIn}/unmatch`;
        const api = mockApi({
            ...detailRoutes(manager, [
                line({
                    cash_transaction_id: bookId,
                    matched_at: '2026-03-16T01:00:00+00:00',
                    matched_by: 1,
                }),
            ]),
            [unmatch]: () => json({ data: line() }),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/bank-statements/${statementId}`);
        await typist.click(
            await screen.findByRole('button', {
                name: 'Batalkan kecocokan baris 2',
            }),
        );

        await waitFor(() => expect(api.called(unmatch)).toHaveLength(1));
    });

    it('is not found for a statement that does not exist', async () => {
        mockApi({
            ...detailRoutes(reader),
            [`GET ${base}/bank-statements/${statementId}`]: () =>
                json({ message: 'Not found', code: 'NOT_FOUND' }, 404),
        });

        renderApp(`${home}/bank-statements/${statementId}`);

        await waitFor(() =>
            expect(screen.queryByText('Rekonsiliasi')).toBeNull(),
        );
        expect(await screen.findByText(/tidak ditemukan/i)).toBeInTheDocument();
    });
});
