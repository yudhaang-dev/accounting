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

const preparerId = '01JROLE00000000000000000001';
const approverId = '01JROLE00000000000000000002';
const retiredId = '01JROLE00000000000000000003';

const role = (overrides: Record<string, unknown> = {}) => ({
    id: preparerId,
    name: 'Preparer',
    description: 'Membuat entri',
    is_active: true,
    permissions: ['entries.create', 'entries.submit'],
    lock_version: 1,
    ...overrides,
});

const roles = [
    role(),
    role({
        id: approverId,
        name: 'Approver',
        description: null,
        permissions: ['entries.approve'],
    }),
    role({
        id: retiredId,
        name: 'Legacy',
        description: null,
        is_active: false,
    }),
];

const catalog = [
    'entries.create',
    'entries.submit',
    'entries.approve',
    'reports.read',
].map((key) => {
    const [resource, action] = key.split('.');

    return { key, resource, action };
});

const branchTypeId = '01JDIMTYPE0000000000000001';
const jakartaId = '01JDIMVALUE000000000000001';
const bandungId = '01JDIMVALUE000000000000002';

const dimensionRoutes = {
    [`GET ${base}/dimension-types`]: () =>
        list([
            {
                id: branchTypeId,
                key: 'branch',
                label: 'Cabang',
                is_hierarchical: false,
                leaf_only: false,
                status: 'active',
                lock_version: 1,
            },
        ]),
    [`GET ${base}/dimension-values`]: () =>
        list(
            [
                [jakartaId, 'JKT', 'Jakarta'],
                [bandungId, 'BDG', 'Bandung'],
            ].map(([id, code, name]) => ({
                id,
                dimension_type_id: branchTypeId,
                code,
                name,
                parent_id: null,
                is_leaf: true,
                valid_from: null,
                valid_to: null,
                status: 'active',
                lock_version: 1,
            })),
        ),
};

const member = (overrides: Record<string, unknown> = {}) => ({
    id: '01JMEMBER0000000000000000001',
    user: { id: 2, name: 'Siti Rahma', email: 'siti@example.com' },
    status: 'active',
    expires_at: null,
    roles: [{ id: preparerId, name: 'Preparer', is_active: true }],
    data_scope: [],
    lock_version: 3,
    ...overrides,
});

const own = member({
    id: '01JMEMBER0000000000000000002',
    user: { id: account.id, name: account.name, email: account.email },
    roles: [{ id: approverId, name: 'Approver', is_active: true }],
    data_scope: [jakartaId, bandungId],
});

const invitation = (overrides: Record<string, unknown> = {}) => ({
    id: '01JINVITE0000000000000000001',
    email: 'baru@example.com',
    status: 'pending',
    role_ids: [approverId],
    data_scope: [],
    membership_expires_at: null,
    expires_at: '2026-12-31T10:00:00+00:00',
    ...overrides,
});

describe('members', () => {
    const path = `${home}/members`;
    const routes = (permissions: string[]) => ({
        ...withPermissions(permissions),
        [`GET ${base}/memberships`]: () => list([member(), own]),
        [`GET ${base}/invitations`]: () => list([invitation()]),
        [`GET ${base}/roles`]: () => list(roles),
        ...dimensionRoutes,
    });
    const all = [
        'memberships.read',
        'memberships.manage',
        'roles.read',
        'dimensions.read',
    ];

    it('lists members with roles and scope, and invitations with the role names', async () => {
        mockApi(routes(['memberships.read', 'roles.read']));
        renderApp(path);

        expect(await screen.findByText('Siti Rahma')).toBeInTheDocument();
        expect(screen.getByText('siti@example.com')).toBeInTheDocument();
        expect(screen.getByText('Dibatasi (2 nilai)')).toBeInTheDocument();
        expect(screen.getByText('baru@example.com')).toBeInTheDocument();
        expect(screen.getByText('Menunggu')).toBeInTheDocument();
        // Read-only: nothing to change.
        expect(
            screen.queryByRole('button', { name: 'Undang anggota' }),
        ).not.toBeInTheDocument();
    });

    it('changes only the roles of a member, with the lock_version it read', async () => {
        const update = `PATCH ${base}/memberships/${member().id}`;
        const api = mockApi({
            ...routes(all),
            [update]: () => json({ data: member({ lock_version: 4 }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Siti Rahma');
        await rowAction(typist, 'Siti Rahma', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            await within(dialog).findByRole('checkbox', { name: 'Approver' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 3,
            role_ids: [preparerId, approverId],
        });
    });

    it('replaces the data scope of a member with the values ticked', async () => {
        const update = `PATCH ${base}/memberships/${member().id}`;
        const api = mockApi({
            ...routes(all),
            [update]: () => json({ data: member({ data_scope: [bandungId] }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Siti Rahma');
        await rowAction(typist, 'Siti Rahma', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            await within(dialog).findByRole('checkbox', {
                name: 'BDG · Bandung',
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 3,
            role_ids: [preparerId],
            data_scope: [bandungId],
        });
    });

    it('invites by email with roles, and refuses an invitation without a role', async () => {
        const invite = `POST ${base}/invitations`;
        const api = mockApi({
            ...routes(all),
            [invite]: () => json({ data: invitation() }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Undang anggota' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Email'),
            'baru@example.com',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Kirim undangan' }),
        );

        expect(
            await within(dialog).findByText('Pilih setidaknya satu role.'),
        ).toBeInTheDocument();
        expect(api.called(invite)).toHaveLength(0);

        // A role that was retired is not offered for a new member.
        expect(
            within(dialog).queryByRole('checkbox', { name: 'Legacy' }),
        ).not.toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('checkbox', { name: 'Approver' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Kirim undangan' }),
        );

        await waitFor(() => expect(api.called(invite)).toHaveLength(1));
        expect(api.called(invite)[0]?.body).toEqual({
            email: 'baru@example.com',
            role_ids: [approverId],
        });
    });

    it('asks before revoking, and offers no revoking of oneself', async () => {
        const revoke = `POST ${base}/memberships/${member().id}/revoke`;
        const api = mockApi({
            ...routes(all),
            [revoke]: () => json({ data: member({ status: 'revoked' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Siti Rahma');
        await rowAction(typist, 'Siti Rahma', 'Cabut akses');

        expect(api.called(revoke)).toHaveLength(0);
        await confirmWith(typist, 'Cabut akses');
        await waitFor(() => expect(api.called(revoke)).toHaveLength(1));

        await typist.click(
            await screen.findByRole('button', {
                name: `Aksi untuk ${account.name}`,
            }),
        );

        expect(
            await screen.findByRole('menuitem', { name: 'Ubah' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Cabut akses' }),
        ).not.toBeInTheDocument();
    });

    it('withdraws a pending invitation after confirmation', async () => {
        const withdraw = `POST ${base}/invitations/${invitation().id}/revoke`;
        const api = mockApi({
            ...routes(all),
            [withdraw]: () => json({ data: invitation({ status: 'revoked' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('baru@example.com');
        await rowAction(typist, 'baru@example.com', 'Tarik undangan');
        await confirmWith(typist, 'Tarik undangan');

        await waitFor(() => expect(api.called(withdraw)).toHaveLength(1));
    });

    it('sends someone without the permission back to the company home', async () => {
        mockApi(withPermissions([]));

        const { router } = renderApp(path);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(home);
        expect(
            screen.queryByRole('link', { name: 'Anggota' }),
        ).not.toBeInTheDocument();
    });
});

describe('roles', () => {
    const path = `${home}/roles`;
    const routes = (permissions: string[]) => ({
        ...withPermissions(permissions),
        [`GET ${base}/roles`]: () => list(roles),
        [`GET ${base}/permissions`]: () => json({ data: catalog }),
    });

    it('lists roles with the number of permissions and their status', async () => {
        mockApi(routes(['roles.read']));
        renderApp(path);

        expect(await screen.findByText('Preparer')).toBeInTheDocument();
        expect(screen.getByText('Membuat entri')).toBeInTheDocument();
        expect(screen.getByText('Nonaktif')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Role baru' }),
        ).not.toBeInTheDocument();
    });

    it('creates a role with the permissions ticked', async () => {
        const create = `POST ${base}/roles`;
        const api = mockApi({
            ...routes(['roles.read', 'roles.manage']),
            [create]: () =>
                json(
                    { data: role({ id: '01JROLE00000000000000000009' }) },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Role baru' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', { name: 'Buat role' }),
        );
        expect(
            await within(dialog).findByText('Wajib diisi'),
        ).toBeInTheDocument();
        expect(api.called(create)).toHaveLength(0);

        await typist.type(within(dialog).getByLabelText('Nama role'), 'Kasir');
        await typist.click(
            await within(dialog).findByRole('checkbox', {
                name: 'reports.read',
            }),
        );
        await typist.click(
            within(dialog).getByRole('checkbox', { name: 'entries.create' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Buat role' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'Kasir',
            description: null,
            permissions: ['reports.read', 'entries.create'],
        });
    });

    it('edits a role with its lock_version and replaces its permissions', async () => {
        const update = `PATCH ${base}/roles/${preparerId}`;
        const api = mockApi({
            ...routes(['roles.read', 'roles.manage']),
            [update]: () => json({ data: role({ lock_version: 2 }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Preparer');
        await rowAction(typist, 'Preparer', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        const submit = await within(dialog).findByRole('checkbox', {
            name: 'entries.submit',
        });

        expect(submit).toBeChecked();
        await typist.click(submit);
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            name: 'Preparer',
            description: 'Membuat entri',
            permissions: ['entries.create'],
        });
    });

    it('asks before deactivating a role, and reactivates without asking', async () => {
        const deactivate = `POST ${base}/roles/${preparerId}/deactivate`;
        const reactivate = `POST ${base}/roles/${retiredId}/reactivate`;
        const api = mockApi({
            ...routes(['roles.read', 'roles.manage']),
            [deactivate]: () => json({ data: role({ is_active: false }) }),
            [reactivate]: () => json({ data: role({ id: retiredId }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Preparer');
        await rowAction(typist, 'Preparer', 'Nonaktifkan');

        expect(api.called(deactivate)).toHaveLength(0);
        await confirmWith(typist, 'Nonaktifkan');
        await waitFor(() => expect(api.called(deactivate)).toHaveLength(1));

        await rowAction(typist, 'Legacy', 'Aktifkan kembali');
        await waitFor(() => expect(api.called(reactivate)).toHaveLength(1));
    });
});

describe('security policy', () => {
    const path = `${home}/security-policy`;
    const policy = (overrides: Record<string, unknown> = {}) => ({
        version: 2,
        two_factor_permissions: ['entries.approve'],
        idle_timeout_minutes: 30,
        client_ip_allowlist: ['203.0.113.10'],
        lock_version: 2,
        ...overrides,
    });
    const put = `PUT ${base}/security-policy`;
    let current = policy();
    const routes = {
        ...withPermissions(['policies.manage', 'roles.read']),
        [`GET ${base}/security-policy`]: () => json({ data: current }),
        [`GET ${base}/permissions`]: () => json({ data: catalog }),
    };

    it('shows the current version and saves a new one with the version it read', async () => {
        current = policy();
        const api = mockApi({
            ...routes,
            [put]: () => {
                current = policy({
                    version: 3,
                    two_factor_permissions: [],
                    idle_timeout_minutes: null,
                    client_ip_allowlist: ['203.0.113.10', '10.0.0.0/8'],
                });

                return json({ data: current });
            },
        });

        renderApp(path);
        const typist = userEvent.setup();

        expect(await screen.findByText('Versi 2')).toBeInTheDocument();
        expect(
            await screen.findByLabelText('Batas sesi idle (menit)'),
        ).toHaveValue('30');

        const approve = await screen.findByRole('checkbox', {
            name: 'entries.approve',
        });

        expect(approve).toBeChecked();
        await typist.click(approve);
        await typist.clear(screen.getByLabelText('Batas sesi idle (menit)'));
        await typist.type(
            screen.getByLabelText('Alamat IP klien integrasi'),
            '{Enter}10.0.0.0/8',
        );
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        await waitFor(() => expect(api.called(put)).toHaveLength(1));
        expect(api.called(put)[0]?.body).toEqual({
            lock_version: 2,
            two_factor_permissions: [],
            idle_timeout_minutes: null,
            client_ip_allowlist: ['203.0.113.10', '10.0.0.0/8'],
        });
        expect(await screen.findByText('Versi 3')).toBeInTheDocument();
    });

    it('refuses an idle limit outside 1 to 1440 minutes without calling the API', async () => {
        const api = mockApi(routes);

        renderApp(path);
        const typist = userEvent.setup();

        const idle = await screen.findByLabelText('Batas sesi idle (menit)');

        await typist.clear(idle);
        await typist.type(idle, '5000');
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(
            await screen.findByText(/Isi bilangan bulat 1–1440/),
        ).toBeInTheDocument();
        expect(api.called(put)).toHaveLength(0);
    });

    it('places a server error about an address next to the address field', async () => {
        mockApi({
            ...routes,
            [put]: () =>
                json(
                    {
                        message: 'invalid',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'client_ip_allowlist.0': ['Alamat IP tidak valid.'],
                        },
                    },
                    422,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByLabelText('Batas sesi idle (menit)');
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(
            await screen.findByText('Alamat IP tidak valid.'),
        ).toBeInTheDocument();
    });
});

describe('integration clients', () => {
    const path = `${home}/integration-clients`;
    const client = (overrides: Record<string, unknown> = {}) => ({
        id: '01JCLIENT0000000000000000001',
        name: 'Kasir Toko',
        source_system: 'pos-toko',
        client_id: '01JPASSPORT000000000000001',
        scopes: ['financial-events:write'],
        transaction_type_keys: ['sales'],
        webhook_url: null,
        webhook_enabled: false,
        data_scope: [],
        status: 'active',
        secret_rotated_at: null,
        revoked_at: null,
        created_at: '2026-10-01T09:00:00+00:00',
        lock_version: 1,
        ...overrides,
    });
    const routes = {
        ...withPermissions(['integration-clients.manage']),
        [`GET ${base}/integration-clients`]: () =>
            list([
                client(),
                client({
                    id: '01JCLIENT0000000000000000002',
                    name: 'Lama',
                    source_system: 'legacy',
                    status: 'revoked',
                }),
            ]),
    };

    it('lists clients, with actions only for active ones', async () => {
        mockApi(routes);
        renderApp(path);

        expect(await screen.findByText('Kasir Toko')).toBeInTheDocument();
        expect(screen.getByText('pos-toko')).toBeInTheDocument();
        expect(screen.getByText('Dicabut')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Aksi untuk Lama' }),
        ).not.toBeInTheDocument();
    });

    it('registers a client and shows its secret once', async () => {
        const create = `POST ${base}/integration-clients`;
        const api = mockApi({
            ...routes,
            [create]: () =>
                json(
                    {
                        data: {
                            ...client(),
                            client_secret: 's3cret-shown-once',
                        },
                    },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Daftarkan klien' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Nama klien'),
            'Kasir Toko',
        );
        await typist.type(
            within(dialog).getByLabelText('Sistem sumber'),
            'Bad System',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Daftarkan' }),
        );

        expect(
            await within(dialog).findByText('Format tidak valid.'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Pilih setidaknya satu scope.'),
        ).toBeInTheDocument();
        expect(api.called(create)).toHaveLength(0);

        await typist.clear(within(dialog).getByLabelText('Sistem sumber'));
        await typist.type(
            within(dialog).getByLabelText('Sistem sumber'),
            'pos-toko',
        );
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: 'financial-events:write',
            }),
        );
        await typist.type(
            within(dialog).getByLabelText('Jenis transaksi yang diizinkan'),
            'sales, refund',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Daftarkan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'Kasir Toko',
            source_system: 'pos-toko',
            scopes: ['financial-events:write'],
            transaction_type_keys: ['sales', 'refund'],
        });

        const secret = await screen.findByLabelText('Client secret');

        expect(secret).toHaveValue('s3cret-shown-once');
        expect(screen.getByLabelText('Client ID')).toHaveValue(
            '01JPASSPORT000000000000001',
        );

        await typist.click(
            screen.getByRole('button', { name: 'Sudah saya simpan' }),
        );

        await waitFor(() =>
            expect(
                screen.queryByDisplayValue('s3cret-shown-once'),
            ).not.toBeInTheDocument(),
        );
    });

    it('rotates a secret only after confirmation, and shows the new one once', async () => {
        const rotate = `POST ${base}/integration-clients/${client().id}/rotate-secret`;
        const api = mockApi({
            ...routes,
            [rotate]: () =>
                json({
                    data: { ...client(), client_secret: 'rotated-secret' },
                }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kasir Toko');
        await rowAction(typist, 'Kasir Toko', 'Rotasi secret');

        expect(api.called(rotate)).toHaveLength(0);
        await confirmWith(typist, 'Rotasi secret');

        await waitFor(() => expect(api.called(rotate)).toHaveLength(1));
        expect(await screen.findByLabelText('Client secret')).toHaveValue(
            'rotated-secret',
        );
    });

    it('edits scopes with the lock_version and keeps the source system fixed', async () => {
        const update = `PATCH ${base}/integration-clients/${client().id}`;
        const api = mockApi({
            ...routes,
            [update]: () => json({ data: client({ lock_version: 2 }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kasir Toko');
        await rowAction(typist, 'Kasir Toko', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByLabelText('Sistem sumber')).toBeDisabled();
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: 'exchange-rates:write',
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            name: 'Kasir Toko',
            scopes: ['financial-events:write', 'exchange-rates:write'],
            transaction_type_keys: ['sales'],
            webhook_url: null,
        });
    });

    it('shows what each client is limited to', async () => {
        mockApi({
            ...withPermissions(['integration-clients.manage']),
            [`GET ${base}/integration-clients`]: () =>
                list([
                    client({ data_scope: [jakartaId] }),
                    client({
                        id: '01JCLIENT0000000000000000002',
                        name: 'Gudang',
                        source_system: 'wms',
                    }),
                ]),
        });

        renderApp(path);

        expect(
            await screen.findByText('Dibatasi (1 nilai)'),
        ).toBeInTheDocument();
        expect(screen.getByText('Tanpa batas')).toBeInTheDocument();
    });

    it('registers a client limited to dimension values when the user may read dimensions', async () => {
        const create = `POST ${base}/integration-clients`;
        const api = mockApi({
            ...routes,
            ...withPermissions([
                'integration-clients.manage',
                'dimensions.read',
            ]),
            ...dimensionRoutes,
            [create]: () =>
                json(
                    {
                        data: {
                            ...client({ data_scope: [jakartaId] }),
                            client_secret: 's3cret-shown-once',
                        },
                    },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Daftarkan klien' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Nama klien'),
            'Kasir Toko',
        );
        await typist.type(
            within(dialog).getByLabelText('Sistem sumber'),
            'pos-toko',
        );
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: 'financial-events:write',
            }),
        );
        await typist.click(
            await within(dialog).findByRole('checkbox', {
                name: 'JKT · Jakarta',
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Daftarkan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            source_system: 'pos-toko',
            data_scope: [jakartaId],
        });
    });

    it('sends the data scope on an edit only when it changed, and not at all without the right to read dimensions', async () => {
        const update = `PATCH ${base}/integration-clients/${client().id}`;
        const api = mockApi({
            ...withPermissions([
                'integration-clients.manage',
                'dimensions.read',
            ]),
            ...dimensionRoutes,
            [`GET ${base}/integration-clients`]: () =>
                list([client({ data_scope: [jakartaId] })]),
            [update]: () => json({ data: client({ lock_version: 2 }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kasir Toko');
        await rowAction(typist, 'Kasir Toko', 'Ubah');
        let dialog = await screen.findByRole('dialog');

        // Nothing touched: the scope is not part of the request.
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );
        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).not.toHaveProperty('data_scope');

        await rowAction(typist, 'Kasir Toko', 'Ubah');
        dialog = await screen.findByRole('dialog');
        await typist.click(
            await within(dialog).findByRole('checkbox', {
                name: 'BDG · Bandung',
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(2));
        expect(api.called(update)[1]?.body).toMatchObject({
            data_scope: expect.arrayContaining([jakartaId, bandungId]),
        });
    });

    it('revokes a client only after confirmation', async () => {
        const revoke = `POST ${base}/integration-clients/${client().id}/revoke`;
        const api = mockApi({
            ...routes,
            [revoke]: () => json({ data: client({ status: 'revoked' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kasir Toko');
        await rowAction(typist, 'Kasir Toko', 'Cabut klien');

        expect(api.called(revoke)).toHaveLength(0);
        await confirmWith(typist, 'Cabut klien');
        await waitFor(() => expect(api.called(revoke)).toHaveLength(1));
    });

    it('sets the webhook URL when registering, and clears it when editing', async () => {
        const create = `POST ${base}/integration-clients`;
        const update = `PATCH ${base}/integration-clients/${client().id}`;
        const api = mockApi({
            ...routes,
            [create]: () =>
                json(
                    { data: { ...client(), client_secret: 'shown-once' } },
                    201,
                ),
            [update]: () => json({ data: client({ lock_version: 2 }) }),
            [`GET ${base}/integration-clients`]: () =>
                list([
                    client({
                        webhook_url: 'https://hooks.example.com/pos',
                        webhook_enabled: true,
                    }),
                ]),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Daftarkan klien' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Nama klien'),
            'Kasir Toko',
        );
        await typist.type(
            within(dialog).getByLabelText('Sistem sumber'),
            'pos-toko',
        );
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: 'financial-events:write',
            }),
        );
        await typist.type(
            within(dialog).getByLabelText('URL webhook'),
            ' https://hooks.example.com/pos ',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Daftarkan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            webhook_url: 'https://hooks.example.com/pos',
        });
        await typist.click(
            await screen.findByRole('button', { name: 'Sudah saya simpan' }),
        );

        await rowAction(typist, 'Kasir Toko', 'Ubah');
        const edit = await screen.findByRole('dialog');

        expect(within(edit).getByLabelText('URL webhook')).toHaveValue(
            'https://hooks.example.com/pos',
        );
        await typist.clear(within(edit).getByLabelText('URL webhook'));
        await typist.click(
            within(edit).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toMatchObject({
            webhook_url: null,
        });
    });

    it('shows the state of the webhook of each client', async () => {
        mockApi({
            ...withPermissions(['integration-clients.manage']),
            [`GET ${base}/integration-clients`]: () =>
                list([
                    client(),
                    client({
                        id: '01JCLIENT0000000000000000003',
                        name: 'Tanpa Secret',
                        source_system: 'nosecret',
                        webhook_url: 'https://hooks.example.com/a',
                    }),
                    client({
                        id: '01JCLIENT0000000000000000004',
                        name: 'Siap',
                        source_system: 'ready',
                        webhook_url: 'https://hooks.example.com/b',
                        webhook_enabled: true,
                    }),
                ]),
        });
        renderApp(path);

        await screen.findByText('Tanpa Secret');

        expect(screen.getByText('Mati')).toBeInTheDocument();
        expect(screen.getByText('Perlu secret')).toBeInTheDocument();
        expect(
            screen.getByText('Aktif', { selector: '[title]' }),
        ).toBeInTheDocument();
    });

    it('issues the webhook secret after confirmation, shows it once, and offers it only with a URL', async () => {
        const issue = `POST ${base}/integration-clients/${client().id}/rotate-webhook-secret`;
        const api = mockApi({
            ...withPermissions(['integration-clients.manage']),
            [`GET ${base}/integration-clients`]: () =>
                list([
                    client({ webhook_url: 'https://hooks.example.com/pos' }),
                    client({
                        id: '01JCLIENT0000000000000000005',
                        name: 'Tanpa URL',
                        source_system: 'nourl',
                    }),
                ]),
            [issue]: () =>
                json({
                    data: {
                        ...client({
                            webhook_url: 'https://hooks.example.com/pos',
                            webhook_enabled: true,
                        }),
                        webhook_secret: 'whsec_shown_once',
                    },
                }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kasir Toko');
        await typist.click(
            screen.getByRole('button', { name: 'Aksi untuk Tanpa URL' }),
        );
        expect(
            screen.queryByRole('menuitem', {
                name: 'Terbitkan secret webhook',
            }),
        ).not.toBeInTheDocument();
        await typist.keyboard('{Escape}');

        await rowAction(typist, 'Kasir Toko', 'Terbitkan secret webhook');

        expect(api.called(issue)).toHaveLength(0);
        await confirmWith(typist, 'Terbitkan secret');

        await waitFor(() => expect(api.called(issue)).toHaveLength(1));
        expect(
            await screen.findByLabelText('Secret penandatangan'),
        ).toHaveValue('whsec_shown_once');

        await typist.click(
            screen.getByRole('button', { name: 'Sudah saya simpan' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByDisplayValue('whsec_shown_once'),
            ).not.toBeInTheDocument(),
        );
    });
});

describe('accepting an invitation', () => {
    const accept = 'POST /api/v1/invitations/accept';

    it('joins only on the click, with the token from the link, and opens the workspace', async () => {
        const joined = { ...workspace, permissions: ['company.read'] };
        let workspaces: unknown[] = [];
        const api = mockApi({
            'GET /api/v1/me': () => json({ data: account }),
            'GET /api/v1/me/workspaces': () => json({ data: workspaces }),
            [`GET ${base}`]: () =>
                json({ data: { ...company, status: 'active' } }),
            [accept]: () => {
                workspaces = [joined];

                return json({ data: joined });
            },
        });

        const { router } = renderApp('/invitations/accept?token=abc123');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Terima undangan' }),
        );

        await waitFor(() => expect(api.called(accept)).toHaveLength(1));
        expect(api.called(accept)[0]?.body).toEqual({ token: 'abc123' });
        await waitFor(() => expect(router.state.location.pathname).toBe(home));
    });

    it('shows what the server said when the invitation cannot be accepted', async () => {
        mockApi({
            'GET /api/v1/me': () => json({ data: account }),
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
            [accept]: () =>
                json(
                    {
                        message: 'Undangan ini sudah tidak berlaku.',
                        code: 'GONE',
                    },
                    410,
                ),
        });

        renderApp('/invitations/accept?token=old');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Terima undangan' }),
        );

        expect(
            await screen.findByText('Undangan ini sudah tidak berlaku.'),
        ).toBeInTheDocument();
    });

    it('lets a visitor with no account make one with a name and a password, then opens the workspace', async () => {
        const joined = { ...workspace, permissions: ['company.read'] };
        let session = false;
        const api = mockApi({
            'GET /api/v1/me': () =>
                session
                    ? json({ data: account })
                    : json({ message: 'x', code: 'UNAUTHENTICATED' }, 401),
            'GET /api/v1/me/workspaces': () =>
                json({ data: session ? [joined] : [] }),
            [`GET ${base}`]: () =>
                json({ data: { ...company, status: 'active' } }),
            [accept]: () => {
                session = true;

                return json({ data: joined });
            },
        });

        const { router } = renderApp('/invitations/accept?token=abc123');
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Nama'), 'Budi');
        await typist.type(
            screen.getByLabelText('Kata sandi'),
            'Secret-pass-123',
        );
        await typist.type(
            screen.getByLabelText('Ulangi kata sandi'),
            'Secret-pass-123',
        );
        await typist.click(
            screen.getByRole('button', {
                name: 'Buat akun dan terima undangan',
            }),
        );

        await waitFor(() => expect(api.called(accept)).toHaveLength(1));
        expect(api.called(accept)[0]?.body).toEqual({
            token: 'abc123',
            name: 'Budi',
            password: 'Secret-pass-123',
            password_confirmation: 'Secret-pass-123',
        });
        await waitFor(() => expect(router.state.location.pathname).toBe(home));
    });

    it('asks to sign in when the invited address already has an account', async () => {
        mockApi({
            'GET /api/v1/me': () =>
                json({ message: 'x', code: 'UNAUTHENTICATED' }, 401),
            [accept]: () =>
                json(
                    { message: 'Unauthenticated.', code: 'UNAUTHENTICATED' },
                    401,
                ),
        });

        renderApp('/invitations/accept?token=abc123');
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Nama'), 'Budi');
        await typist.type(
            screen.getByLabelText('Kata sandi'),
            'Secret-pass-123',
        );
        await typist.type(
            screen.getByLabelText('Ulangi kata sandi'),
            'Secret-pass-123',
        );
        await typist.click(
            screen.getByRole('button', {
                name: 'Buat akun dan terima undangan',
            }),
        );

        expect(await screen.findByText(/sudah punya akun/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Masuk' })).toBeInTheDocument();
    });

    it('does not offer to accept a link without a token', async () => {
        mockApi({
            'GET /api/v1/me': () => json({ data: account }),
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
        });

        renderApp('/invitations/accept');

        expect(
            await screen.findByText(/Tautan undangan ini tidak lengkap/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Terima undangan' }),
        ).not.toBeInTheDocument();
    });
});
