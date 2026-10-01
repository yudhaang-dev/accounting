import { screen, waitFor, within } from '@testing-library/react';
import { pickDate } from '@/test/pickers';
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
const entriesPath = `/w/${workspace.company_id}/entries`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const cash = '01JACCOUNT0000000000000001';
const sales = '01JACCOUNT0000000000000002';

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

const accountList = () =>
    json({
        data: [
            accountRow(cash, '1111', 'Kas Kecil'),
            accountRow(sales, '4100', 'Penjualan'),
        ],
        links: {},
        meta: { current_page: 1, last_page: 1, total: 2 },
    });

const entry = (overrides: Record<string, unknown> = {}) => ({
    id: '01JENTRY00000000000000001',
    number: 'JU-2026-0001',
    status: 'draft',
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
            description: 'Modal awal',
        },
    ],
    created_by: 1,
    journal_id: null,
    journal_number: null,
    posted_at: null,
    decisions: [],
    lock_version: 1,
    ...overrides,
});

const entryPath = (id = entry().id) => `${base}/entries/${id}`;

const page = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const type = {
    id: '01JTYPE000000000000000001',
    key: 'general',
    label: 'Jurnal Umum',
    versions: [{ version: 1, mode: 'free_lines', status: 'published' }],
    lock_version: 1,
};

const all = [
    'entries.read',
    'entries.create',
    'entries.submit',
    'entries.approve',
    'entries.reject',
    'entries.post',
];

describe('entry list', () => {
    it('lists entries with their status and total', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/entries`]: () =>
                page([
                    entry(),
                    entry({
                        id: '01JENTRY00000000000000002',
                        number: 'JU-2026-0002',
                        status: 'posted',
                    }),
                ]),
        });

        renderApp(entriesPath);

        expect(await screen.findByText('JU-2026-0001')).toBeInTheDocument();
        expect(screen.getAllByText('1.500.000,50')).toHaveLength(2);
        expect(screen.getByText('Draf')).toBeInTheDocument();
        expect(screen.getByText('Diposting')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Entri baru' }),
        ).not.toBeInTheDocument();
    });

    it('offers a new entry only to those who may create one', async () => {
        mockApi({
            ...withPermissions(['entries.read', 'entries.create']),
            [`GET ${base}/entries`]: () => page([]),
        });

        renderApp(entriesPath);

        expect(await screen.findByText('Belum ada entri')).toBeInTheDocument();
        expect(
            screen.getAllByRole('link', { name: 'Entri baru' }).length,
        ).toBeGreaterThan(0);
    });

    it('filters by date on the server', async () => {
        const api = mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/entries`]: () => page([entry()]),
        });

        renderApp(entriesPath);
        const typist = userEvent.setup();

        await pickDate(
            typist,
            await screen.findByRole('button', { name: /Dari tanggal/ }),
            '2026-10-01',
        );

        await waitFor(() =>
            expect(
                api
                    .called(`GET ${base}/entries`)
                    .some(
                        (call) => call.query['filter[from]'] === '2026-10-01',
                    ),
            ).toBe(true),
        );
    });
});

describe('entry form', () => {
    const formRoutes = (permissions: string[], types: unknown[] = [type]) => ({
        ...withPermissions(permissions),
        [`GET ${base}/transaction-types`]: () => json({ data: types }),
        [`GET ${base}/accounts`]: accountList,
    });

    it('keeps one side per line and totals with exact decimals', async () => {
        mockApi(formRoutes(['entries.read', 'entries.create']));

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        const debit = await screen.findByLabelText('Debit Baris 1');
        const credit = screen.getByLabelText('Kredit Baris 1');

        await typist.type(debit, '0,1');
        await typist.type(screen.getByLabelText('Kredit Baris 2'), '0,2');

        expect(screen.getByText('Selisih 0,1')).toBeInTheDocument();

        await typist.type(credit, '5');
        expect(debit).toHaveValue('');
        expect(screen.getByLabelText('Debit Baris 1')).toHaveValue('');
    });

    it('says when a line is incomplete or the amount is not a number', async () => {
        const api = mockApi(formRoutes(['entries.read', 'entries.create']));

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Keterangan'), 'Tes');
        await typist.type(screen.getByLabelText('Debit Baris 1'), '0');
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(
            await screen.findAllByText(/Isi angka lebih dari nol/),
        ).not.toHaveLength(0);
        expect(screen.getAllByText('Wajib diisi').length).toBeGreaterThan(0);
        expect(api.called(`POST ${base}/entries`)).toHaveLength(0);
    });

    it('creates a balanced draft by choosing accounts and typing amounts', async () => {
        const create = `POST ${base}/entries`;
        const api = mockApi({
            ...formRoutes(['entries.read', 'entries.create']),
            [create]: () => json({ data: entry() }, 201),
            [`GET ${entryPath()}`]: () => json({ data: entry() }),
        });

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Keterangan'),
            'Setoran modal',
        );

        await typist.click(
            screen.getByRole('combobox', { name: 'Akun Baris 1' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '1111 · Kas Kecil' }),
        );
        await typist.type(screen.getByLabelText('Debit Baris 1'), '1500000,50');

        await typist.click(
            screen.getByRole('combobox', { name: 'Akun Baris 2' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '4100 · Penjualan' }),
        );
        await typist.type(
            screen.getByLabelText('Kredit Baris 2'),
            '1500000,50',
        );

        expect(screen.getByText('Seimbang')).toBeInTheDocument();
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            transaction_type_key: 'general',
            description: 'Setoran modal',
            lines: [
                {
                    account_id: cash,
                    debit: '1500000.50',
                    credit: null,
                    description: null,
                },
                {
                    account_id: sales,
                    debit: null,
                    credit: '1500000.50',
                    description: null,
                },
            ],
        });
        expect(
            await screen.findByRole('heading', { name: 'Entri JU-2026-0001' }),
        ).toBeInTheDocument();
    });

    it('lets an administrator create the first transaction type', async () => {
        const api = mockApi({
            ...formRoutes(
                ['entries.read', 'entries.create', 'policies.manage'],
                [],
            ),
            [`POST ${base}/transaction-types`]: () => json({ data: type }, 201),
        });

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Kunci'), 'general');
        await typist.type(screen.getByLabelText('Nama jenis'), 'Jurnal Umum');
        await typist.click(screen.getByRole('button', { name: 'Buat jenis' }));

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/transaction-types`)[0]?.body,
            ).toEqual({
                key: 'general',
                label: 'Jurnal Umum',
            }),
        );
    });

    it('tells everyone else to ask an administrator', async () => {
        mockApi(formRoutes(['entries.read', 'entries.create'], []));

        renderApp(`${entriesPath}/new`);

        expect(
            await screen.findByText(/Setiap entri memerlukan jenis transaksi/),
        ).toBeInTheDocument();
    });

    it('edits a draft, sending its version and the lines as typed decimals', async () => {
        const path = `PATCH ${entryPath()}`;
        const api = mockApi({
            ...formRoutes(['entries.read', 'entries.create']),
            [`GET ${entryPath()}`]: () => json({ data: entry() }),
            [path]: () =>
                json({
                    data: entry({
                        description: 'Setoran modal awal',
                        lock_version: 2,
                    }),
                }),
        });

        renderApp(`${entriesPath}/${entry().id}/edit`);
        const typist = userEvent.setup();

        const description = await screen.findByLabelText('Keterangan');

        expect(screen.getByLabelText('Debit Baris 1')).toHaveValue(
            '1.500.000,50',
        );
        await typist.clear(description);
        await typist.type(description, 'Setoran modal awal');
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(path)).toHaveLength(1));
        expect(api.called(path)[0]?.body).toEqual({
            lock_version: 1,
            accounting_date: '2026-10-02',
            document_date: null,
            description: 'Setoran modal awal',
            lines: [
                {
                    account_id: cash,
                    debit: '1500000.50',
                    credit: null,
                    description: null,
                },
                {
                    account_id: sales,
                    debit: null,
                    credit: '1500000.50',
                    description: 'Modal awal',
                },
            ],
        });
    });

    it('will not edit an entry that has left draft', async () => {
        mockApi({
            ...formRoutes(['entries.read', 'entries.create']),
            [`GET ${entryPath()}`]: () =>
                json({ data: entry({ status: 'submitted' }) }),
        });

        renderApp(`${entriesPath}/${entry().id}/edit`);

        expect(
            await screen.findByText(
                'Hanya entri berstatus draf yang dapat diubah.',
            ),
        ).toBeInTheDocument();
    });
});

describe('transaction type names', () => {
    const typeRoute = {
        [`GET ${base}/transaction-types`]: () => json({ data: [type] }),
    };

    it('shows the name of the type in the list, not its key', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            ...typeRoute,
            [`GET ${base}/entries`]: () => page([entry()]),
        });

        renderApp(entriesPath);

        expect(await screen.findByText('Jurnal Umum')).toBeInTheDocument();
        expect(screen.queryByText('general')).not.toBeInTheDocument();
    });

    it('shows it on the entry too', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            ...typeRoute,
            [`GET ${base}/accounts`]: accountList,
            [`GET ${entryPath()}`]: () => json({ data: entry() }),
        });

        renderApp(`${entriesPath}/${entry().id}`);

        expect(await screen.findByText('Jurnal Umum')).toBeInTheDocument();
    });

    it('falls back to the key for a type that is gone', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/transaction-types`]: () => json({ data: [] }),
            [`GET ${base}/entries`]: () =>
                page([entry({ transaction_type_key: 'retired' })]),
        });

        renderApp(entriesPath);

        expect(await screen.findByText('retired')).toBeInTheDocument();
    });
});

describe('creating a transaction type', () => {
    const noTypes = () => ({
        ...withPermissions([
            'entries.read',
            'entries.create',
            'policies.manage',
        ]),
        [`GET ${base}/transaction-types`]: () => json({ data: [] }),
        [`GET ${base}/accounts`]: accountList,
    });

    it('writes the key from the name as it is typed', async () => {
        mockApi(noTypes());

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Nama jenis'),
            'Jurnal Penyesuaian',
        );

        expect(screen.getByLabelText('Kunci')).toHaveValue(
            'jurnal_penyesuaian',
        );
    });

    it('stops following the name once the user writes a key', async () => {
        const api = mockApi({
            ...noTypes(),
            [`POST ${base}/transaction-types`]: () => json({ data: type }, 201),
        });

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Nama jenis'), 'Jurnal');
        await typist.clear(screen.getByLabelText('Kunci'));
        await typist.type(screen.getByLabelText('Kunci'), 'general');
        await typist.type(screen.getByLabelText('Nama jenis'), ' Umum');

        expect(screen.getByLabelText('Kunci')).toHaveValue('general');
        await typist.click(screen.getByRole('button', { name: 'Buat jenis' }));

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/transaction-types`)[0]?.body,
            ).toEqual({
                key: 'general',
                label: 'Jurnal Umum',
            }),
        );
    });

    it('follows the name again when the key is emptied', async () => {
        mockApi(noTypes());

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Kunci'), 'x');
        await typist.type(screen.getByLabelText('Nama jenis'), 'Kas Masuk');
        expect(screen.getByLabelText('Kunci')).toHaveValue('x');

        await typist.clear(screen.getByLabelText('Kunci'));
        await typist.type(screen.getByLabelText('Nama jenis'), ' Harian');

        expect(screen.getByLabelText('Kunci')).toHaveValue('kas_masuk_harian');
    });
});

describe('entry lifecycle', () => {
    const detailRoutes = (
        permissions: string[],
        current: Record<string, unknown> = {},
    ) => ({
        ...withPermissions(permissions),
        [`GET ${base}/accounts`]: accountList,
        [`GET ${entryPath()}`]: () => json({ data: entry(current) }),
    });

    it('shows the lines with account names and exact totals', async () => {
        mockApi(detailRoutes(['entries.read']));

        renderApp(`${entriesPath}/${entry().id}`);

        expect(await screen.findByText('1111 · Kas Kecil')).toBeInTheDocument();
        expect(screen.getByText('4100 · Penjualan')).toBeInTheDocument();
        expect(screen.getByText('Modal awal')).toBeInTheDocument();
        expect(
            screen.getAllByText('1.500.000,50').length,
        ).toBeGreaterThanOrEqual(4);
    });

    it('offers a draft its own actions only', async () => {
        mockApi(detailRoutes(all));

        renderApp(`${entriesPath}/${entry().id}`);

        expect(
            await screen.findByRole('button', { name: 'Ajukan' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Ubah' })).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Batalkan' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Setujui' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Posting' }),
        ).not.toBeInTheDocument();
    });

    it('hides actions the user has no permission for', async () => {
        mockApi(detailRoutes(['entries.read']));

        renderApp(`${entriesPath}/${entry().id}`);

        await screen.findByText('1111 · Kas Kecil');
        expect(
            screen.queryByRole('button', { name: 'Ajukan' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Ubah' }),
        ).not.toBeInTheDocument();
    });

    it('submits a draft', async () => {
        const submit = `POST ${entryPath()}/submit`;
        const api = mockApi({
            ...detailRoutes(all),
            [submit]: () => json({ data: entry({ status: 'submitted' }) }),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Ajukan' }),
        );

        await waitFor(() => expect(api.called(submit)).toHaveLength(1));
    });

    it('shows why the server refused a step, in its own words', async () => {
        mockApi({
            ...detailRoutes(all),
            [`POST ${entryPath()}/submit`]: () =>
                json(
                    {
                        message: 'Jurnal tidak seimbang: debit 10, kredit 8.',
                        code: 'UNBALANCED_ENTRY',
                    },
                    422,
                ),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Ajukan' }),
        );

        expect(
            await screen.findByText(
                'Jurnal tidak seimbang: debit 10, kredit 8.',
            ),
        ).toBeInTheDocument();
    });

    it('approves a submitted entry', async () => {
        const approve = `POST ${entryPath()}/approve`;
        const api = mockApi({
            ...detailRoutes(all, { status: 'submitted' }),
            [approve]: () => json({ data: entry({ status: 'approved' }) }),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Setujui' }),
        );

        await waitFor(() => expect(api.called(approve)).toHaveLength(1));
    });

    it('will not reject without a reason', async () => {
        const reject = `POST ${entryPath()}/reject`;
        const api = mockApi({
            ...detailRoutes(all, { status: 'submitted' }),
            [reject]: () => json({ data: entry({ status: 'rejected' }) }),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tolak' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', { name: 'Tolak entri' }),
        );
        expect(
            await within(dialog).findByText('Wajib diisi'),
        ).toBeInTheDocument();
        expect(api.called(reject)).toHaveLength(0);

        await typist.type(
            within(dialog).getByLabelText('Alasan'),
            'Nominal salah',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Tolak entri' }),
        );

        await waitFor(() =>
            expect(api.called(reject)[0]?.body).toEqual({
                reason: 'Nominal salah',
            }),
        );
    });

    it('posts an approved entry', async () => {
        const post = `POST ${entryPath()}/post`;
        const api = mockApi({
            ...detailRoutes(all, { status: 'approved' }),
            [post]: () => json({ data: entry({ status: 'posted' }) }),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Posting' }),
        );

        await waitFor(() => expect(api.called(post)).toHaveLength(1));
    });

    it('shows the journal of a posted entry and no more actions', async () => {
        mockApi(
            detailRoutes(all, {
                status: 'posted',
                journal_id: '01JJOURNAL0000000000000001',
                journal_number: 'JV-2026-0001',
            }),
        );

        renderApp(`${entriesPath}/${entry().id}`);

        expect(
            await screen.findByText('Diposting sebagai jurnal JV-2026-0001'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Batalkan' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Posting' }),
        ).not.toBeInTheDocument();
    });

    it('takes a rejected entry back to draft', async () => {
        const revise = `POST ${entryPath()}/revise`;
        const api = mockApi({
            ...detailRoutes(all, { status: 'rejected' }),
            [revise]: () => json({ data: entry() }),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Revisi' }),
        );

        await waitFor(() => expect(api.called(revise)).toHaveLength(1));
    });

    it('names who decided, and "you" for the signed-in user', async () => {
        mockApi(
            detailRoutes(['entries.read'], {
                status: 'rejected',
                decisions: [
                    {
                        action: 'submit',
                        actor_id: account.id,
                        reason: null,
                        at: '2026-10-02T08:00:00+00:00',
                    },
                    {
                        action: 'reject',
                        actor_id: 7,
                        reason: 'Nominal salah',
                        at: '2026-10-02T09:00:00+00:00',
                    },
                ],
            }),
        );

        renderApp(`${entriesPath}/${entry().id}`);

        expect(await screen.findByText(/Anda/)).toBeInTheDocument();
        expect(screen.getByText(/Pengguna #7/)).toBeInTheDocument();
        expect(screen.getByText('Alasan: Nominal salah')).toBeInTheDocument();
    });
});
