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
const path = `${home}/posting-profiles`;

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

const defaultId = '01JPROFILE000000000000001';
const relatedId = '01JPROFILE000000000000002';
const receivableId = '01JACCOUNT0000000000000001';
const payableId = '01JACCOUNT0000000000000002';

const accountRow = (overrides: Record<string, unknown>) => ({
    id: receivableId,
    code: '1210',
    name: 'Piutang Usaha',
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    allowed_sources: ['subledger'],
    dimension_requirements: {},
    currency_restriction: 'any',
    restricted_currency: null,
    is_revaluable: false,
    lock_version: 1,
    ...overrides,
});

const chart = [
    accountRow({}),
    accountRow({
        id: '01JACCOUNT0000000000000003',
        code: '1220',
        name: 'Piutang Umum',
        allowed_sources: ['entry', 'subledger'],
    }),
    accountRow({
        id: '01JACCOUNT0000000000000004',
        code: '1230',
        name: 'Piutang Lama',
        status: 'inactive',
    }),
    accountRow({
        id: payableId,
        code: '2110',
        name: 'Utang Usaha',
        class: 'liability',
        normal_balance: 'credit',
    }),
];

const role = (key: string, overrides: Record<string, unknown> = {}) => ({
    key,
    account_id: null,
    effective_date: null,
    valid: false,
    lock_version: 0,
    ...overrides,
});

const profile = (overrides: Record<string, unknown> = {}) => ({
    id: defaultId,
    name: 'Pihak ketiga',
    is_default: true,
    lock_version: 1,
    accounts: [
        role('receivables_control', {
            account_id: receivableId,
            effective_date: '2026-01-01',
            valid: true,
            lock_version: 1,
        }),
        role('payables_control'),
    ],
    ...overrides,
});

const routes = (permissions: string[], profiles = [profile()]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/posting-profiles`]: () => json({ data: profiles }),
    [`GET ${base}/accounts`]: () => list(chart),
});

const manage = ['posting-profiles.read', 'posting-profiles.manage'];

describe('posting profiles (SL-FR-02)', () => {
    it('lists each profile with the account behind every control role', async () => {
        mockApi(
            routes(manage, [
                profile(),
                profile({
                    id: relatedId,
                    name: 'Pihak berelasi',
                    is_default: false,
                    accounts: [
                        role('receivables_control', {
                            account_id: receivableId,
                            valid: false,
                            lock_version: 2,
                        }),
                        role('payables_control'),
                    ],
                }),
            ]),
        );

        renderApp(path);

        expect(
            await screen.findByRole('heading', { name: 'Pihak ketiga' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Pihak berelasi' }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Default')).toHaveLength(1);
        expect(screen.getAllByText('Dipetakan')).toHaveLength(1);
        expect(screen.getByText('Akun tidak lagi valid')).toBeInTheDocument();
        expect(screen.getAllByText('Belum dipetakan')).toHaveLength(2);
    });

    it('offers only active leaf accounts of the right class that accept the subledger alone', async () => {
        const put = `PUT ${base}/posting-profiles/${defaultId}/accounts/payables_control`;
        const api = mockApi({
            ...routes(manage),
            [put]: () => json({ data: profile() }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByRole('heading', { name: 'Pihak ketiga' });
        await typist.click(
            screen.getByRole('combobox', {
                name: 'Akun kontrol utang, Pihak ketiga',
            }),
        );

        expect(
            await screen.findByRole('option', { name: '2110 · Utang Usaha' }),
        ).toBeInTheDocument();
        // Not an asset, not subledger-only, not active.
        expect(screen.queryByRole('option', { name: /1210/ })).toBeNull();

        await typist.click(
            screen.getByRole('option', { name: '2110 · Utang Usaha' }),
        );
        await typist.click(
            screen.getByRole('button', {
                name: 'Simpan Akun kontrol utang, Pihak ketiga',
            }),
        );

        await waitFor(() => expect(api.called(put)).toHaveLength(1));
        expect(api.called(put)[0]?.body).toEqual({
            lock_version: 0,
            account_id: payableId,
        });
    });

    it('does not offer accounts that accept entries or are inactive for receivables', async () => {
        mockApi(routes(manage));

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByRole('heading', { name: 'Pihak ketiga' });
        await typist.click(
            screen.getByRole('combobox', {
                name: 'Akun kontrol piutang, Pihak ketiga',
            }),
        );

        expect(
            await screen.findByRole('option', { name: '1210 · Piutang Usaha' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /1220/ })).toBeNull();
        expect(screen.queryByRole('option', { name: /1230/ })).toBeNull();
    });

    it('points to the chart when no account qualifies', async () => {
        mockApi({
            ...routes([...manage, 'accounts.manage']),
            [`GET ${base}/accounts`]: () =>
                list([
                    accountRow({
                        id: payableId,
                        code: '2110',
                        class: 'liability',
                        allowed_sources: ['entry', 'subledger', 'system'],
                    }),
                ]),
        });

        renderApp(path);

        expect(
            await screen.findByText(
                /Belum ada akun Aset yang hanya menerima sumber subledger/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getAllByRole('link', { name: 'Atur di daftar akun' })[0],
        ).toHaveAttribute('href', `${home}/accounts`);
    });

    it('adds a profile', async () => {
        const create = `POST ${base}/posting-profiles`;
        const api = mockApi({
            ...routes(manage),
            [create]: () =>
                json(
                    { data: profile({ name: 'Valas', is_default: false }) },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah profil' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'Valas');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'Valas',
            is_default: false,
        });
    });

    it('renames the default profile without trying to clear the default', async () => {
        const update = `PATCH ${base}/posting-profiles/${defaultId}`;
        const api = mockApi({
            ...routes(manage),
            [update]: () => json({ data: profile({ name: 'Umum' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Ubah profil Pihak ketiga',
            }),
        );
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog).getByRole('checkbox', {
                name: 'Jadikan profil default',
            }),
        ).toHaveAttribute('aria-disabled', 'true');

        await typist.clear(within(dialog).getByLabelText('Nama'));
        await typist.type(within(dialog).getByLabelText('Nama'), 'Umum');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            name: 'Umum',
        });
    });

    it('moves the default to another profile', async () => {
        const update = `PATCH ${base}/posting-profiles/${relatedId}`;
        const api = mockApi({
            ...routes(manage, [
                profile(),
                profile({
                    id: relatedId,
                    name: 'Pihak berelasi',
                    is_default: false,
                }),
            ]),
            [update]: () => json({ data: profile({ id: relatedId }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Ubah profil Pihak berelasi',
            }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: 'Jadikan profil default',
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            name: 'Pihak berelasi',
            is_default: true,
        });
    });

    it('offers no changes to someone who may only read', async () => {
        mockApi(routes(['posting-profiles.read']));

        renderApp(path);

        await screen.findByRole('heading', { name: 'Pihak ketiga' });
        expect(
            screen.queryByRole('button', { name: 'Tambah profil' }),
        ).toBeNull();
        expect(screen.queryByRole('button', { name: /^Simpan / })).toBeNull();
        expect(
            screen.getByRole('combobox', {
                name: 'Akun kontrol piutang, Pihak ketiga',
            }),
        ).toBeDisabled();
    });

    it('is a menu item only for those who may read profiles', async () => {
        mockApi(withPermissions(['posting-profiles.read']));

        renderApp(home);

        expect(
            await screen.findByRole('link', { name: 'Profil posting' }),
        ).toBeInTheDocument();
    });
});
