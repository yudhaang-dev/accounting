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

type Routes = Parameters<typeof mockApi>[0];

const base = `/api/v1/companies/${workspace.company_id}`;
const entriesPath = `/w/${workspace.company_id}/entries`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const expense = '01JACCOUNT0000000000000005';

const version = (overrides: Record<string, unknown> = {}) => ({
    version: 1,
    mode: 'structured',
    status: 'published',
    fields: [],
    posting_rules: [],
    allowed_currencies: null,
    rate_type_key: null,
    allow_rate_input: false,
    ...overrides,
});

const payroll = (overrides: Record<string, unknown> = {}) => ({
    id: '01JTYPE000000000000000002',
    key: 'payroll_summary',
    label: 'Ringkasan Payroll',
    numbering: {
        pattern: 'PAY/{yyyy}/{seq}',
        padding: 4,
        reset: 'yearly',
        policy: 'on_create',
    },
    versions: [version()],
    lock_version: 1,
    ...overrides,
});

const schema = {
    $schema: 'https://json-schema.org/draft/2020-12/schema',
    type: 'object',
    properties: {
        payroll_date: {
            title: 'Tanggal payroll',
            type: 'string',
            format: 'date',
        },
        gross_salary: {
            title: 'Total gaji bruto',
            type: 'string',
            pattern: '^[0-9]+(\\.[0-9]+)?$',
            'x-scale': 'currency',
        },
        expense_account: {
            title: 'Akun beban gaji',
            type: 'string',
            'x-reference': 'account',
        },
        note: { title: 'Catatan', type: 'string', maxLength: 500 },
        payer: {
            title: 'Pihak pembayar',
            type: 'string',
            'x-reference': 'party',
        },
    },
    required: ['gross_salary', 'expense_account', 'payer'],
    additionalProperties: false,
};

const accountList = () =>
    json({
        data: [
            {
                id: expense,
                code: '5100',
                name: 'Beban Gaji',
                class: 'expense',
                normal_balance: 'debit',
                parent_id: null,
                is_leaf: true,
                status: 'active',
                lock_version: 1,
            },
        ],
        links: {},
        meta: { current_page: 1, last_page: 1, total: 1 },
    });

const currencies = () =>
    json({
        data: [
            {
                code: 'IDR',
                name: 'Rupiah',
                minor_unit: 2,
                scale: 2,
                rounding_mode: 'half_up',
                is_functional: true,
                status: 'active',
                lock_version: 1,
            },
            {
                code: 'USD',
                name: 'US Dollar',
                minor_unit: 2,
                scale: 2,
                rounding_mode: 'half_up',
                is_functional: false,
                status: 'active',
                lock_version: 1,
            },
        ],
        links: {},
        meta: { current_page: 1, last_page: 1, total: 2 },
    });

const customer = {
    id: '01JPARTY000000000000000001',
    name: 'Toko Budi',
    roles: ['customer'],
    tax_id: null,
    posting_profile_id: null,
    status: 'active',
    external_refs: [],
    lock_version: 1,
};

const page = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const pickPayer = async (typist: ReturnType<typeof userEvent.setup>) => {
    await typist.click(
        screen.getByRole('combobox', { name: 'Pihak pembayar' }),
    );
    await typist.click(
        await screen.findByRole('option', { name: 'Toko Budi' }),
    );
};

const entry = (overrides: Record<string, unknown> = {}) => ({
    id: '01JENTRY00000000000000009',
    number: 'PAY/2026/0001',
    status: 'draft',
    source: 'manual',
    transaction_type_key: 'payroll_summary',
    transaction_type_version: 1,
    accounting_date: '2026-10-02',
    document_date: null,
    description: 'Gaji Oktober',
    lines: [],
    values: {
        payroll_date: '2026-10-01',
        gross_salary: '15000000',
        expense_account: expense,
        payer: customer.id,
    },
    currency: null,
    exchange_rate: null,
    created_by: 1,
    journal_id: null,
    journal_number: null,
    posted_at: null,
    decisions: [],
    lock_version: 1,
    ...overrides,
});

const form = (types: unknown[], overrides: Routes = {}): Routes => ({
    ...withPermissions(['entries.read', 'entries.create']),
    [`GET ${base}/transaction-types`]: () => json({ data: types }),
    [`GET ${base}/transaction-types/${payroll().id}/versions/1/schema`]: () =>
        json({ data: schema }),
    [`GET ${base}/accounts`]: accountList,
    [`GET ${base}/currencies`]: currencies,
    [`GET ${base}/parties`]: () => page([customer]),
    ...overrides,
});

describe('structured entry form', () => {
    it('draws the inputs from the schema of the version and saves the values', async () => {
        const create = `POST ${base}/entries`;
        const api = mockApi(
            form([payroll()], {
                [create]: () => json({ data: entry() }, 201),
                [`GET ${base}/entries/${entry().id}`]: () =>
                    json({ data: entry() }),
            }),
        );

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Keterangan'),
            'Gaji Oktober',
        );
        await typist.type(
            await screen.findByLabelText('Total gaji bruto'),
            '15000000',
        );
        await typist.type(
            screen.getByLabelText('Catatan (opsional)'),
            'Termin 1',
        );
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun beban gaji' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '5100 · Beban Gaji' }),
        );
        await pickPayer(typist);

        // The journal lines are not typed here: the posting rules make them.
        expect(screen.queryByText('Tambah baris')).not.toBeInTheDocument();

        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            transaction_type_key: 'payroll_summary',
            transaction_type_version: 1,
            description: 'Gaji Oktober',
            values: {
                gross_salary: '15000000',
                expense_account: expense,
                payer: customer.id,
                note: 'Termin 1',
            },
            currency: null,
            exchange_rate: null,
        });
        expect(api.called(create)[0]?.body).not.toHaveProperty('lines');
    });

    it('offers only the accounts of the classes the field accepts (TE-FR-04)', async () => {
        const restricted = {
            ...schema,
            properties: {
                ...schema.properties,
                expense_account: {
                    ...schema.properties.expense_account,
                    'x-account-classes': ['expense'],
                },
            },
        };
        mockApi(
            form([payroll()], {
                [`GET ${base}/transaction-types/${payroll().id}/versions/1/schema`]:
                    () => json({ data: restricted }),
                [`GET ${base}/accounts`]: () =>
                    json({
                        data: [
                            {
                                id: expense,
                                code: '5100',
                                name: 'Beban Gaji',
                                class: 'expense',
                                normal_balance: 'debit',
                                parent_id: null,
                                is_leaf: true,
                                status: 'active',
                                lock_version: 1,
                            },
                            {
                                id: '01JACCOUNT0000000000000BANK',
                                code: '1112',
                                name: 'Bank Operasional',
                                class: 'asset',
                                normal_balance: 'debit',
                                parent_id: null,
                                is_leaf: true,
                                status: 'active',
                                lock_version: 1,
                            },
                        ],
                        links: {},
                        meta: { current_page: 1, last_page: 1, total: 2 },
                    }),
            }),
        );

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('combobox', { name: 'Akun beban gaji' }),
        );

        expect(
            await screen.findByRole('option', { name: '5100 · Beban Gaji' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: '1112 · Bank Operasional' }),
        ).not.toBeInTheDocument();
    });

    it('says which fields are needed before asking the server', async () => {
        const create = `POST ${base}/entries`;
        const api = mockApi(form([payroll()], { [create]: () => json({}) }));

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Keterangan'),
            'Gaji Oktober',
        );
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(
            (await screen.findAllByText('Wajib diisi')).length,
        ).toBeGreaterThan(0);
        expect(api.called(create)).toHaveLength(0);
    });

    it('picks the party of a field from the subledger', async () => {
        mockApi(form([payroll()]));

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        expect(await screen.findByText('Pihak pembayar')).toBeInTheDocument();

        await typist.click(
            screen.getByRole('combobox', { name: 'Pihak pembayar' }),
        );

        expect(
            await screen.findByRole('option', { name: 'Toko Budi' }),
        ).toBeInTheDocument();
    });

    it('offers only the parties that hold a role the field accepts', async () => {
        const lender = {
            ...customer,
            id: '01JPARTY000000000000000002',
            name: 'Bank Mitra',
            roles: ['lender'],
        };

        mockApi(
            form([payroll()], {
                [`GET ${base}/transaction-types/${payroll().id}/versions/1/schema`]:
                    () =>
                        json({
                            data: {
                                ...schema,
                                properties: {
                                    ...schema.properties,
                                    payer: {
                                        ...schema.properties.payer,
                                        'x-allowed-roles': ['lender'],
                                    },
                                },
                            },
                        }),
                [`GET ${base}/parties`]: () => page([customer, lender]),
            }),
        );

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        await screen.findByText('Pihak pembayar');
        await typist.click(
            screen.getByRole('combobox', { name: 'Pihak pembayar' }),
        );

        expect(
            await screen.findByRole('option', { name: 'Bank Mitra' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Toko Budi' }),
        ).not.toBeInTheDocument();
    });

    it('records a payment with its cash account and the open items it settles', async () => {
        const create = `POST ${base}/entries`;
        const payment = payroll({
            id: '01JTYPE000000000000000003',
            key: 'customer_payment',
            label: 'Pembayaran Pelanggan',
            versions: [
                version({
                    subledger_route: {
                        kind: 'payment',
                        control_role: 'receivables_control',
                        party_field: 'customer',
                        document_number_field: null,
                        due_date_field: null,
                        cash_account_field: 'bank',
                        counterpart_cash_account_field: null,
                        allocations_field: 'settles',
                    },
                }),
            ],
        });
        const openItem = {
            id: '01JOPENITEM00000000000001',
            document_id: '01JDOCUMENT0000000000001',
            party_id: customer.id,
            control_role: 'receivables_control',
            kind: 'invoice',
            side: 'debit',
            document_number: 'INV-001',
            document_date: '2026-09-01',
            due_date: '2026-09-30',
            currency: 'IDR',
            original_amount: '1000000',
            remaining_amount: '1000000',
            original_functional: '1000000',
            remaining_functional: '1000000',
            exchange_rate: '1',
            status: 'open',
        };
        const api = mockApi(
            form([payment], {
                [`GET ${base}/transaction-types/${payment.id}/versions/1/schema`]:
                    () =>
                        json({
                            data: {
                                type: 'object',
                                properties: {
                                    customer: {
                                        title: 'Pelanggan',
                                        type: 'string',
                                        'x-reference': 'party',
                                    },
                                    bank: {
                                        title: 'Rekening bank',
                                        type: 'string',
                                        'x-reference': 'cash_account',
                                    },
                                    amount: {
                                        title: 'Nominal diterima',
                                        type: 'string',
                                        'x-scale': 'currency',
                                    },
                                    settles: {
                                        title: 'Pelunasan',
                                        type: 'array',
                                        items: { type: 'object' },
                                    },
                                },
                                required: ['customer', 'bank', 'amount'],
                            },
                        }),
                [`GET ${base}/cash-accounts`]: () =>
                    page([
                        {
                            id: '01JCASH0000000000000000001',
                            name: 'Bank BCA',
                            kind: 'bank',
                            bank_name: 'BCA',
                            account_number: null,
                            currency: 'IDR',
                            account_id: '01JACCOUNT0000000000000001',
                            status: 'active',
                            lock_version: 1,
                        },
                    ]),
                [`GET ${base}/open-items`]: () => page([openItem]),
                [create]: () => json({ data: entry() }, 201),
                [`GET ${base}/entries/${entry().id}`]: () =>
                    json({ data: entry() }),
            }),
        );

        renderApp(`${entriesPath}/new?type=customer_payment`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Keterangan'),
            'Pelunasan INV-001',
        );
        await typist.type(
            await screen.findByLabelText('Nominal diterima'),
            '500000',
        );

        // Nothing can be settled before the party is known.
        expect(
            screen.getByRole('button', { name: 'Tambah alokasi' }),
        ).toBeDisabled();

        await typist.click(screen.getByRole('combobox', { name: 'Pelanggan' }));
        await typist.click(
            await screen.findByRole('option', { name: 'Toko Budi' }),
        );
        await typist.click(
            screen.getByRole('combobox', { name: 'Rekening bank' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'Bank BCA · IDR' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Tambah alokasi' }),
        );
        await typist.click(
            screen.getByRole('combobox', { name: 'Open item alokasi 1' }),
        );
        await typist.click(
            await screen.findByRole('option', {
                name: 'INV-001 · sisa 1.000.000,00 IDR',
            }),
        );
        await typist.type(screen.getByLabelText('Nominal alokasi 1'), '500000');
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            transaction_type_key: 'customer_payment',
            values: {
                customer: customer.id,
                bank: '01JCASH0000000000000000001',
                amount: '500000',
                settles: [{ open_item_id: openItem.id, amount: '500000' }],
            },
        });
    });

    it('puts the refusal of a field next to the field', async () => {
        mockApi(
            form([payroll()], {
                [`POST ${base}/entries`]: () =>
                    json(
                        {
                            message: 'Data tidak valid.',
                            errors: {
                                'values.gross_salary': [
                                    'Nominal melebihi skala mata uang.',
                                ],
                            },
                        },
                        422,
                    ),
            }),
        );

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Keterangan'), 'Gaji');
        await typist.type(screen.getByLabelText('Total gaji bruto'), '1');
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun beban gaji' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '5100 · Beban Gaji' }),
        );
        await pickPayer(typist);
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(
            await screen.findByText('Nominal melebihi skala mata uang.'),
        ).toBeInTheDocument();
    });

    it('offers only the currencies the version allows and asks for a rate when it may', async () => {
        const create = `POST ${base}/entries`;
        const api = mockApi(
            form(
                [
                    payroll({
                        versions: [
                            version({
                                allowed_currencies: ['IDR', 'USD'],
                                allow_rate_input: true,
                            }),
                        ],
                    }),
                ],
                {
                    [create]: () => json({ data: entry() }, 201),
                    [`GET ${base}/entries/${entry().id}`]: () =>
                        json({ data: entry() }),
                },
            ),
        );

        renderApp(`${entriesPath}/new?type=payroll_summary`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Keterangan'),
            'Gaji USD',
        );
        await typist.type(screen.getByLabelText('Total gaji bruto'), '1000');
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun beban gaji' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '5100 · Beban Gaji' }),
        );
        await pickPayer(typist);

        // The functional currency needs no rate.
        expect(screen.queryByLabelText(/^Kurs/)).not.toBeInTheDocument();

        await typist.click(screen.getByRole('combobox', { name: 'Mata uang' }));
        await typist.click(await screen.findByRole('option', { name: 'USD' }));
        await typist.type(
            await screen.findByLabelText('Kurs USD ke IDR'),
            '15.850,5',
        );
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            currency: 'USD',
            exchange_rate: '15850.5',
        });
    });

    it('does not offer a type that has no published version', async () => {
        mockApi(
            form([
                payroll({
                    versions: [version({ status: 'retired' })],
                }),
            ]),
        );

        renderApp(`${entriesPath}/new`);

        expect(
            await screen.findByText(/Belum ada jenis transaksi yang terbit/),
        ).toBeInTheDocument();
    });

    it('edits a draft with the values it was saved with', async () => {
        const update = `PATCH ${base}/entries/${entry().id}`;
        const api = mockApi(
            form([payroll()], {
                [`GET ${base}/entries/${entry().id}`]: () =>
                    json({ data: entry() }),
                [update]: () => json({ data: entry() }),
            }),
        );

        renderApp(`${entriesPath}/${entry().id}/edit`);
        const typist = userEvent.setup();

        expect(await screen.findByLabelText('Total gaji bruto')).toHaveValue(
            '15.000.000',
        );

        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toMatchObject({
            lock_version: 1,
            values: {
                gross_salary: '15000000',
                expense_account: expense,
                payer: customer.id,
            },
        });
    });
});

describe('structured entry detail', () => {
    it('shows the values under the labels of the version', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/entries/${entry().id}`]: () =>
                json({ data: entry() }),
            [`GET ${base}/transaction-types`]: () =>
                json({ data: [payroll()] }),
            [`GET ${base}/transaction-types/${payroll().id}/versions/1/schema`]:
                () => json({ data: schema }),
            [`GET ${base}/accounts`]: accountList,
            [`GET ${base}/parties`]: () => page([customer]),
        });

        renderApp(`${entriesPath}/${entry().id}`);

        const card = (await screen.findByText('Isian entri')).closest(
            '[data-slot="card"]',
        ) as HTMLElement;

        expect(await within(card).findByText('Total gaji bruto')).toBeVisible();
        expect(within(card).getByText('15.000.000')).toBeVisible();
        expect(
            await within(card).findByText('5100 · Beban Gaji'),
        ).toBeVisible();
        expect(await within(card).findByText('Toko Budi')).toBeVisible();
    });
});
