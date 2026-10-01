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

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const row = (overrides: Record<string, unknown>) => ({
    id: '01JACCOUNT0000000000000001',
    code: '1111',
    name: 'Kas Kecil',
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const list = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const id1 = '01JACCOUNT0000000000000001';
const id2 = '01JACCOUNT0000000000000002';
const id3 = '01JACCOUNT0000000000000003';

const accounts = [
    row({ id: id1, code: '1000', name: 'ASET', is_leaf: false }),
    row({ id: id2, code: '1111', name: 'Kas Kecil', parent_id: id1 }),
    row({ id: id3, code: '1113', name: 'Bank Khusus', status: 'inactive' }),
];

const accountsPath = `/w/${workspace.company_id}/accounts`;
const periodsPath = `/w/${workspace.company_id}/periods`;

describe('chart of accounts', () => {
    it('lists the accounts, marking parents and inactive ones', async () => {
        mockApi({
            ...withPermissions(['accounts.read']),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);

        expect(await screen.findByText('Kas Kecil')).toBeInTheDocument();
        expect(screen.getByText('Induk')).toBeInTheDocument();
        expect(screen.getByText('3 akun')).toBeInTheDocument();
    });

    it('offers no editing to someone who may only read', async () => {
        mockApi({
            ...withPermissions(['accounts.read']),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);

        await screen.findByText('Kas Kecil');
        expect(
            screen.queryByRole('button', { name: 'Tambah akun' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^Ubah/ }),
        ).not.toBeInTheDocument();
    });

    it('sends someone without the permission back to the company home', async () => {
        mockApi({ ...withPermissions([]) });

        const { router } = renderApp(accountsPath);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
        expect(
            screen.queryByRole('link', { name: 'Akun' }),
        ).not.toBeInTheDocument();
    });

    it('only shows menu entries the user may open', async () => {
        mockApi({ ...withPermissions(['accounts.read']) });

        renderApp(`/w/${workspace.company_id}`);

        expect(
            await screen.findByRole('link', { name: 'Akun' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Periode' }),
        ).not.toBeInTheDocument();
    });

    it('filters on the server by what is typed', async () => {
        const api = mockApi({
            ...withPermissions(['accounts.read']),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByRole('searchbox', {
                name: 'Cari kode atau nama',
            }),
            'kas',
        );

        await waitFor(() =>
            expect(
                api
                    .called(`GET ${base}/accounts`)
                    .some((call) => call.query['filter[search]'] === 'kas'),
            ).toBe(true),
        );
    });

    it('adds an account', async () => {
        const api = mockApi({
            ...withPermissions(['accounts.read', 'accounts.manage']),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`POST ${base}/accounts`]: () =>
                json({ data: row({ code: '1200', name: 'Piutang' }) }, 201),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah akun' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Kode'), '1200');
        await typist.type(
            within(dialog).getByLabelText('Nama akun'),
            'Piutang',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/accounts`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/accounts`)[0]?.body).toEqual({
            code: '1200',
            name: 'Piutang',
            class: 'asset',
            normal_balance: null,
            parent_id: null,
        });
    });

    it('shows a duplicate code next to the field', async () => {
        mockApi({
            ...withPermissions(['accounts.read', 'accounts.manage']),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`POST ${base}/accounts`]: () =>
                json(
                    {
                        message: 'Invalid',
                        errors: { code: ['Kode akun 1111 sudah dipakai.'] },
                    },
                    422,
                ),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah akun' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Kode'), '1111');
        await typist.type(within(dialog).getByLabelText('Nama akun'), 'Kas');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText('Kode akun 1111 sudah dipakai.'),
        ).toBeInTheDocument();
    });

    it('edits an account with its version', async () => {
        const path = `PATCH ${base}/accounts/${id2}`;
        const api = mockApi({
            ...withPermissions(['accounts.read', 'accounts.manage']),
            [`GET ${base}/accounts`]: () => list(accounts),
            [path]: () =>
                json({ data: row({ name: 'Kas Besar', lock_version: 2 }) }),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Ubah 1111' }),
        );
        const dialog = await screen.findByRole('dialog');
        const name = within(dialog).getByLabelText('Nama akun');

        await typist.clear(name);
        await typist.type(name, 'Kas Besar');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(path)).toHaveLength(1));
        expect(api.called(path)[0]?.body).toMatchObject({
            lock_version: 1,
            name: 'Kas Besar',
            code: '1111',
        });
    });

    it('reloads and explains when someone else changed the account first', async () => {
        const api = mockApi({
            ...withPermissions(['accounts.read', 'accounts.manage']),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`PATCH ${base}/accounts/${id2}`]: () =>
                json({ message: 'Conflict', code: 'VERSION_CONFLICT' }, 409),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Ubah 1111' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText(/Akun sudah diubah di tempat lain/),
        ).toBeInTheDocument();
        expect(api.called(`GET ${base}/accounts`).length).toBeGreaterThan(1);
    });

    it('deactivates and reactivates an account', async () => {
        const off = `POST ${base}/accounts/${id2}/deactivate`;
        const on = `POST ${base}/accounts/${id3}/reactivate`;
        const api = mockApi({
            ...withPermissions(['accounts.read', 'accounts.manage']),
            [`GET ${base}/accounts`]: () => list(accounts),
            [off]: () => json({ data: row({ status: 'inactive' }) }),
            [on]: () => json({ data: row({ status: 'active' }) }),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Nonaktifkan 1111' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Aktifkan kembali 1113' }),
        );

        await waitFor(() => {
            expect(api.called(off)).toHaveLength(1);
            expect(api.called(on)).toHaveLength(1);
        });
    });

    it('says the chart is empty when there are no accounts at all', async () => {
        mockApi({
            ...withPermissions(['accounts.read']),
            [`GET ${base}/accounts`]: () => list([]),
        });

        renderApp(accountsPath);

        expect(await screen.findByText('Belum ada akun')).toBeInTheDocument();
    });
});

const year = {
    id: '01JYEAR000000000000000001',
    name: 'FY2026',
    start_date: '2026-01-01',
    end_date: '2026-12-31',
    period_count: 12,
};

const period = (n: number, status: string) => ({
    id: `01JPERIOD00000000000000${String(n).padStart(3, '0')}`,
    fiscal_year_id: year.id,
    number: n,
    name: `2026-${String(n).padStart(2, '0')}`,
    start_date: `2026-${String(n).padStart(2, '0')}-01`,
    end_date: `2026-${String(n).padStart(2, '0')}-28`,
    status,
});

describe('fiscal periods', () => {
    const periodRoutes = (permissions: string[]) => ({
        ...withPermissions(permissions),
        [`GET ${base}/fiscal-years`]: () => json({ data: [year] }),
        [`GET ${base}/periods`]: () =>
            list([period(1, 'closed'), period(2, 'open')]),
    });

    it('lists the periods of the fiscal year', async () => {
        mockApi(periodRoutes(['periods.read']));

        renderApp(periodsPath);

        expect(await screen.findByText('2026-01')).toBeInTheDocument();
        expect(screen.getByText('2026-02')).toBeInTheDocument();
        expect(screen.getByText('Ditutup')).toBeInTheDocument();
        expect(screen.getByText('Terbuka')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^Tutup/ }),
        ).not.toBeInTheDocument();
    });

    it('closes an open period', async () => {
        const close = `POST ${base}/periods/${period(2, 'open').id}/close`;
        const api = mockApi({
            ...periodRoutes(['periods.read', 'periods.close']),
            [close]: () => json({ data: period(2, 'closed') }),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tutup 2026-02' }),
        );

        await waitFor(() => expect(api.called(close)).toHaveLength(1));
    });

    it('will not reopen a period without a reason', async () => {
        const reopen = `POST ${base}/periods/${period(1, 'closed').id}/reopen`;
        const api = mockApi({
            ...periodRoutes(['periods.read', 'periods.reopen']),
            [reopen]: () => json({ data: period(1, 'open') }),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Buka kembali 2026-01' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', { name: 'Buka kembali' }),
        );
        expect(
            await within(dialog).findByText('Wajib diisi'),
        ).toBeInTheDocument();
        expect(api.called(reopen)).toHaveLength(0);

        await typist.type(
            within(dialog).getByLabelText('Alasan'),
            'Koreksi audit',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Buka kembali' }),
        );

        await waitFor(() =>
            expect(api.called(reopen)[0]?.body).toEqual({
                reason: 'Koreksi audit',
            }),
        );
    });

    it('adds the next fiscal year', async () => {
        const create = `POST ${base}/fiscal-years`;
        const api = mockApi({
            ...periodRoutes(['periods.read', 'periods.manage']),
            [create]: () =>
                json(
                    {
                        data: [
                            {
                                ...year,
                                id: '01JYEAR000000000000000002',
                                name: 'FY2027',
                            },
                        ],
                    },
                    201,
                ),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah tahun fiskal' }),
        );

        await waitFor(() =>
            expect(api.called(create)[0]?.body).toEqual({ count: 1 }),
        );
    });

    it('invites creating a fiscal year when there is none', async () => {
        mockApi({
            ...withPermissions(['periods.read', 'periods.manage']),
            [`GET ${base}/fiscal-years`]: () => json({ data: [] }),
        });

        renderApp(periodsPath);

        expect(
            await screen.findByText('Belum ada tahun fiskal'),
        ).toBeInTheDocument();
    });
});
