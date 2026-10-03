import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { confirmWith, rowAction } from '@/test/actions';
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
const typesPath = `/w/${workspace.company_id}/transaction-types`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const manage = ['entries.read', 'policies.manage'];

const version = (overrides: Record<string, unknown> = {}) => ({
    version: 1,
    mode: 'free_lines',
    status: 'published',
    fields: null,
    posting_rules: null,
    allowed_currencies: null,
    rate_type_key: null,
    allow_rate_input: false,
    ...overrides,
});

const typeRow = (overrides: Record<string, unknown> = {}) => ({
    id: '01JTYPE000000000000000001',
    key: 'general_journal',
    label: 'Jurnal Umum',
    numbering: {
        pattern: 'JU/{yyyy}/{mm}/{seq}',
        padding: 4,
        reset: 'monthly',
        policy: 'on_create',
    },
    versions: [version()],
    lock_version: 1,
    ...overrides,
});

const typeId = typeRow().id;
const listing = (types: unknown[]) => () => json({ data: types });

const payroll = (overrides: Record<string, unknown> = {}) =>
    typeRow({
        id: '01JTYPE000000000000000002',
        key: 'payroll_summary',
        label: 'Ringkasan Payroll',
        versions: [
            version({
                mode: 'structured',
                fields: [
                    {
                        name: 'gross_salary',
                        type: 'amount',
                        label: 'Total gaji bruto',
                        required: true,
                    },
                    {
                        name: 'expense_account',
                        type: 'account',
                        label: 'Akun beban gaji',
                        required: true,
                    },
                ],
                posting_rules: [
                    {
                        type: 'amount',
                        side: 'debit',
                        amount_field: 'gross_salary',
                        account: { source: 'field', field: 'expense_account' },
                        apply_dimensions: true,
                        reuses_field: false,
                    },
                ],
            }),
            version({
                version: 2,
                status: 'draft',
                mode: 'structured',
                fields: [
                    {
                        name: 'gross_salary',
                        type: 'amount',
                        label: 'Total gaji bruto',
                        required: true,
                    },
                    {
                        name: 'expense_account',
                        type: 'account',
                        label: 'Akun beban gaji',
                        required: true,
                    },
                ],
                posting_rules: [
                    {
                        type: 'amount',
                        side: 'debit',
                        amount_field: 'gross_salary',
                        account: { source: 'field', field: 'expense_account' },
                        apply_dimensions: true,
                        reuses_field: false,
                    },
                ],
            }),
        ],
        ...overrides,
    });

const emptyPage = () =>
    json({
        data: [],
        links: {},
        meta: { current_page: 1, last_page: 1, total: 0 },
    });

/** The lists the designer reads to offer accounts, currencies, rate types and dimension types. */
const lookups = {
    [`GET ${base}/accounts`]: () =>
        json({
            data: [
                {
                    id: '01JACCOUNT0000000000000001',
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
        }),
    [`GET ${base}/currencies`]: () =>
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
        }),
    [`GET ${base}/rate-types`]: emptyPage,
    [`GET ${base}/dimension-types`]: emptyPage,
};

describe('transaction type list', () => {
    it('lists the types with their active version and number pattern', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/transaction-types`]: listing([typeRow(), payroll()]),
        });

        renderApp(typesPath);

        expect(await screen.findByText('Jurnal Umum')).toBeInTheDocument();
        expect(screen.getByText('Ringkasan Payroll')).toBeInTheDocument();
        expect(screen.getAllByText('JU/{yyyy}/{mm}/{seq}')).toHaveLength(2);
        expect(screen.getByText('Draf v2')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Jenis baru' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Impor template' }),
        ).not.toBeInTheDocument();
    });

    it('creates a type from the dialog', async () => {
        const create = `POST ${base}/transaction-types`;
        const api = mockApi({
            ...withPermissions(manage),
            [`GET ${base}/transaction-types`]: listing([typeRow()]),
            [create]: () => json({ data: typeRow() }, 201),
        });

        renderApp(typesPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Jenis baru' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Nama jenis'),
            'Kas Kecil',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Buat jenis' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            key: 'kas_kecil',
            label: 'Kas Kecil',
        });
    });

    it('sets the number series and when the number is given', async () => {
        const numbering = `PUT ${base}/transaction-types/${typeId}/numbering`;
        const api = mockApi({
            ...withPermissions(manage),
            [`GET ${base}/transaction-types`]: listing([typeRow()]),
            [numbering]: () => json({ data: typeRow() }),
        });

        renderApp(typesPath);
        const typist = userEvent.setup();

        await rowAction(typist, 'Jurnal Umum', 'Atur penomoran');
        const dialog = await screen.findByRole('dialog');
        const pattern = within(dialog).getByLabelText('Pola nomor');

        await typist.clear(pattern);
        await typist.click(pattern);
        await typist.paste('GJ-{yyyy}-{seq}');

        expect(within(dialog).getByText(/^GJ-\d{4}-0001$/)).toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('combobox', { name: 'Nomor diberikan' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'Saat entri diajukan' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(numbering)).toHaveLength(1));
        expect(api.called(numbering)[0]?.body).toEqual({
            pattern: 'GJ-{yyyy}-{seq}',
            padding: 4,
            reset: 'monthly',
            policy: 'on_submit',
        });
    });

    it('shows why a pattern is refused next to the field', async () => {
        mockApi({
            ...withPermissions(manage),
            [`GET ${base}/transaction-types`]: listing([typeRow()]),
            [`PUT ${base}/transaction-types/${typeId}/numbering`]: () =>
                json(
                    {
                        message: 'Data tidak valid.',
                        errors: { pattern: ['Awalan JU sudah dipakai.'] },
                    },
                    422,
                ),
        });

        renderApp(typesPath);
        const typist = userEvent.setup();

        await rowAction(typist, 'Jurnal Umum', 'Atur penomoran');
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText('Awalan JU sudah dipakai.'),
        ).toBeInTheDocument();
    });
});

describe('template import', () => {
    const template = {
        key: 'general',
        name: 'Jenis Transaksi umum',
        version: 1,
        types: [
            {
                key: 'general_journal',
                label: 'Jurnal Umum',
                mode: 'free_lines',
                fields: [],
                posting_rules: [],
            },
            {
                key: 'payroll_summary',
                label: 'Ringkasan Payroll',
                mode: 'structured',
                fields: [
                    {
                        name: 'gross_salary',
                        type: 'amount',
                        label: 'Total gaji bruto',
                    },
                    {
                        name: 'expense_account',
                        type: 'account',
                        label: 'Akun beban gaji',
                    },
                ],
                posting_rules: [
                    {
                        type: 'amount',
                        side: 'debit',
                        amount_field: 'gross_salary',
                        account: { source: 'field', field: 'expense_account' },
                        apply_dimensions: true,
                        reuses_field: true,
                    },
                ],
            },
        ],
    };

    const routes = (overrides: Routes = {}) => ({
        ...withPermissions(manage),
        [`GET ${base}/transaction-types`]: listing([typeRow()]),
        [`GET ${base}/transaction-type-templates`]: () =>
            json({
                data: [
                    {
                        key: 'general',
                        name: 'Jenis Transaksi umum',
                        version: 1,
                        type_count: 2,
                    },
                ],
            }),
        [`GET ${base}/transaction-type-templates/general`]: () =>
            json({ data: template }),
        ...overrides,
    });

    it('shows what a type would do and imports only after the user confirms', async () => {
        const importing = `POST ${base}/transaction-types/import-template`;
        const api = mockApi(
            routes({ [importing]: () => json({ data: {} }, 201) }),
        );

        renderApp(typesPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Impor template' }),
        );
        const dialog = await screen.findByRole('dialog');

        // Each suggested type is readable before it is created, down to its posting rules.
        expect(
            await within(dialog).findByText('Ringkasan Payroll'),
        ).toBeVisible();
        expect(
            within(dialog).getByText(
                'Debit sebesar Total gaji bruto pada akun dari field “Akun beban gaji”',
            ),
        ).toBeVisible();
        // A key the company already has cannot be imported again.
        expect(within(dialog).getByText('Sudah ada')).toBeVisible();

        const submit = within(dialog).getByRole('button', {
            name: 'Impor 1 jenis',
        });

        expect(submit).toBeDisabled();

        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: /Saya sudah meninjau/,
            }),
        );
        await typist.click(submit);

        await waitFor(() => expect(api.called(importing)).toHaveLength(1));
        expect(api.called(importing)[0]?.body).toEqual({
            template: 'general',
            confirmed: true,
            keys: ['payroll_summary'],
        });
    });

    it('lets the user leave a type out', async () => {
        mockApi(routes({ [`GET ${base}/transaction-types`]: listing([]) }));

        renderApp(typesPath);
        const typist = userEvent.setup();

        await typist.click(
            (
                await screen.findAllByRole('button', { name: 'Impor template' })
            )[0]!,
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            await within(dialog).findByRole('checkbox', {
                name: /Jurnal Umum/,
            }),
        );

        expect(
            within(dialog).getByRole('button', { name: 'Impor 1 jenis' }),
        ).toBeInTheDocument();
    });
});

describe('version designer', () => {
    const designer = (id = payroll().id) => `${typesPath}/${id}`;
    const route = (overrides: Routes = {}) => ({
        ...withPermissions(manage),
        ...lookups,
        [`GET ${base}/transaction-types/${payroll().id}`]: () =>
            json({ data: payroll() }),
        ...overrides,
    });

    it('opens the draft in an editor and the published version as a read-only package', async () => {
        mockApi(route());

        renderApp(designer());
        const typist = userEvent.setup();

        expect(await screen.findByLabelText('Label field 1')).toHaveValue(
            'Total gaji bruto',
        );

        await typist.click(screen.getByRole('button', { name: /v1/ }));

        expect(
            await screen.findByText(
                'Debit sebesar Total gaji bruto pada akun dari field “Akun beban gaji”',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Simpan draf' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Hentikan' }),
        ).toBeInTheDocument();
    });

    it('saves the draft as the API takes it', async () => {
        const save = `PUT ${base}/transaction-types/${payroll().id}/versions/2`;
        const api = mockApi(route({ [save]: () => json({ data: payroll() }) }));

        renderApp(designer());
        const typist = userEvent.setup();
        const label = await screen.findByLabelText('Label field 1');

        await typist.clear(label);
        await typist.type(label, 'Gaji bruto');
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(save)).toHaveLength(1));
        expect(api.called(save)[0]?.body).toEqual({
            mode: 'structured',
            fields: [
                {
                    name: 'gross_salary',
                    type: 'amount',
                    label: 'Gaji bruto',
                    required: true,
                },
                {
                    name: 'expense_account',
                    type: 'account',
                    label: 'Akun beban gaji',
                    required: true,
                },
            ],
            posting_rules: [
                {
                    type: 'amount',
                    side: 'debit',
                    amount_field: 'gross_salary',
                    account: { source: 'field', field: 'expense_account' },
                    apply_dimensions: true,
                    reuses_field: false,
                },
            ],
            allowed_currencies: null,
            rate_type_key: null,
            allow_rate_input: false,
        });
    });

    it('refuses a field name that is not an identifier before asking the server', async () => {
        const save = `PUT ${base}/transaction-types/${payroll().id}/versions/2`;
        const api = mockApi(route({ [save]: () => json({ data: payroll() }) }));

        renderApp(designer());
        const typist = userEvent.setup();
        const name = await screen.findByLabelText('Nama field field 1');

        await typist.clear(name);
        await typist.type(name, 'Gaji Bruto');
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(
            await screen.findByText(/Huruf kecil, angka, dan garis bawah/),
        ).toBeInTheDocument();
        expect(api.called(save)).toHaveLength(0);
    });

    it('puts a refused rule next to the rule', async () => {
        mockApi(
            route({
                [`PUT ${base}/transaction-types/${payroll().id}/versions/2`]:
                    () =>
                        json(
                            {
                                message: 'Data tidak valid.',
                                errors: {
                                    'posting_rules.0.account.field': [
                                        'Field akun tidak ditemukan.',
                                    ],
                                },
                            },
                            422,
                        ),
            }),
        );

        renderApp(designer());
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Simpan draf' }),
        );

        const alerts = await screen.findAllByRole('alert');

        expect(
            alerts.some((alert) =>
                within(alert).queryByText('Field akun tidak ditemukan.'),
            ),
        ).toBe(true);
    });

    it('publishes a draft only after the user confirms', async () => {
        const publish = `POST ${base}/transaction-types/${payroll().id}/versions/2/publish`;
        const api = mockApi(
            route({ [publish]: () => json({ data: payroll() }) }),
        );

        renderApp(designer());
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Terbitkan' }),
        );

        expect(api.called(publish)).toHaveLength(0);

        await confirmWith(typist, 'Terbitkan');

        await waitFor(() => expect(api.called(publish)).toHaveLength(1));
    });

    it('says what stops a publish', async () => {
        mockApi(
            route({
                [`POST ${base}/transaction-types/${payroll().id}/versions/2/publish`]:
                    () =>
                        json(
                            {
                                message: 'Data tidak valid.',
                                errors: {
                                    'posting_rules.0.reuses_field': [
                                        'Field dipakai aturan lain tanpa dinyatakan.',
                                    ],
                                },
                            },
                            422,
                        ),
            }),
        );

        renderApp(designer());
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Terbitkan' }),
        );
        await confirmWith(typist, 'Terbitkan');

        expect(
            await screen.findByText(
                /Field dipakai aturan lain tanpa dinyatakan/,
            ),
        ).toBeInTheDocument();
    });

    it('retires a published version after confirmation', async () => {
        const retire = `POST ${base}/transaction-types/${payroll().id}/versions/1/retire`;
        const api = mockApi(
            route({ [retire]: () => json({ data: payroll() }) }),
        );

        renderApp(designer());
        const typist = userEvent.setup();

        await typist.click(await screen.findByRole('button', { name: /v1/ }));
        await typist.click(
            await screen.findByRole('button', { name: 'Hentikan' }),
        );
        await confirmWith(typist, 'Hentikan versi');

        await waitFor(() => expect(api.called(retire)).toHaveLength(1));
    });

    it('starts the next version from the latest one', async () => {
        const draft = `POST ${base}/transaction-types/${payroll().id}/versions`;
        const api = mockApi(
            route({ [draft]: () => json({ data: payroll() }, 201) }),
        );

        renderApp(designer());
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Versi baru' }),
        );

        await waitFor(() => expect(api.called(draft)).toHaveLength(1));
        expect(api.called(draft)[0]?.body).toMatchObject({
            mode: 'structured',
            fields: [
                { name: 'gross_salary', type: 'amount' },
                { name: 'expense_account', type: 'account' },
            ],
        });
    });

    it('lets a viewer read the versions without editing them', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            ...lookups,
            [`GET ${base}/transaction-types/${payroll().id}`]: () =>
                json({ data: payroll() }),
        });

        renderApp(designer());

        expect(
            await screen.findByText(
                'Debit sebesar Total gaji bruto pada akun dari field “Akun beban gaji”',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Versi baru' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Terbitkan' }),
        ).not.toBeInTheDocument();
    });
});
