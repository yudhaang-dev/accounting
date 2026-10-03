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

        renderApp(`${entriesPath}/new`);
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
                note: 'Termin 1',
            },
            currency: null,
            exchange_rate: null,
        });
        expect(api.called(create)[0]?.body).not.toHaveProperty('lines');
    });

    it('says which fields are needed before asking the server', async () => {
        const create = `POST ${base}/entries`;
        const api = mockApi(form([payroll()], { [create]: () => json({}) }));

        renderApp(`${entriesPath}/new`);
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

    it('leaves out a field the subledger must supply and says so', async () => {
        mockApi(form([payroll()]));

        renderApp(`${entriesPath}/new`);

        expect(await screen.findByText('Pihak pembayar')).toBeInTheDocument();
        expect(
            screen.getByText(/bergantung pada modul subledger/),
        ).toBeInTheDocument();
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

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Keterangan'), 'Gaji');
        await typist.type(screen.getByLabelText('Total gaji bruto'), '1');
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun beban gaji' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '5100 · Beban Gaji' }),
        );
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

        renderApp(`${entriesPath}/new`);
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
            values: { gross_salary: '15000000', expense_account: expense },
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
    });
});
