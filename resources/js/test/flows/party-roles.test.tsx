import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { rowAction } from '@/test/actions';
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
const path = `${home}/party-roles`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const role = (overrides: Record<string, unknown> = {}) => ({
    id: '01JROLE0000000000000001',
    key: 'customer',
    label: 'Pelanggan',
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const templates = () =>
    json({
        data: [
            { key: 'customer', label: 'Pelanggan' },
            { key: 'employee', label: 'Karyawan' },
            { key: 'lender', label: 'Pemberi pinjaman' },
        ],
    });

const manager = ['parties.read', 'parties.manage'];

describe('party roles', () => {
    it('lists the roles with their keys and status', async () => {
        mockApi({
            ...withPermissions(manager),
            [`GET ${base}/party-roles`]: () =>
                json({
                    data: [
                        role(),
                        role({
                            id: '01JROLE0000000000000002',
                            key: 'lender',
                            label: 'Pemberi pinjaman',
                            status: 'inactive',
                        }),
                    ],
                }),
        });

        renderApp(path);

        expect(await screen.findByText('Pelanggan')).toBeInTheDocument();
        expect(screen.getByText('lender')).toBeInTheDocument();
        expect(screen.getByText('Nonaktif')).toBeInTheDocument();
    });

    it('adds a role with a key of its own', async () => {
        const create = `POST ${base}/party-roles`;
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/party-roles`]: () => json({ data: [] }),
            [create]: () => json({ data: role({ key: 'partner' }) }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            (
                await screen.findAllByRole('button', { name: 'Tambah peran' })
            )[0]!,
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'Mitra');
        await typist.type(within(dialog).getByLabelText('Kunci'), 'Bad Key');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText(/^Mulai dengan huruf kecil/),
        ).toBeInTheDocument();
        expect(api.called(create)).toHaveLength(0);

        await typist.clear(within(dialog).getByLabelText('Kunci'));
        await typist.type(within(dialog).getByLabelText('Kunci'), 'partner');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            key: 'partner',
            label: 'Mitra',
        });
    });

    it('imports the standard roles only after the user has read and confirmed them', async () => {
        const importRoles = `POST ${base}/party-roles/import-template`;
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/party-roles`]: () => json({ data: [role()] }),
            [`GET ${base}/party-role-templates`]: templates,
            [importRoles]: () =>
                json({ data: [role({ key: 'employee' })] }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Impor peran bawaan' }),
        );
        const dialog = await screen.findByRole('dialog');

        // A role the company already has cannot be picked again.
        expect(
            await within(dialog).findByRole('checkbox', { name: 'Pelanggan' }),
        ).toHaveAttribute('aria-disabled', 'true');
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: 'Pemberi pinjaman',
            }),
        );

        const submit = within(dialog).getByRole('button', {
            name: 'Buat 1 peran',
        });

        expect(submit).toBeDisabled();
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: /^Saya sudah membaca/,
            }),
        );
        await typist.click(submit);

        await waitFor(() => expect(api.called(importRoles)).toHaveLength(1));
        expect(api.called(importRoles)[0]?.body).toEqual({
            confirmed: true,
            keys: ['employee'],
        });
    });

    it('deactivates a role with the lock version', async () => {
        const update = `PATCH ${base}/party-roles/01JROLE0000000000000001`;
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/party-roles`]: () => json({ data: [role()] }),
            [update]: () => json({ data: role({ status: 'inactive' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Pelanggan');
        await rowAction(typist, 'Pelanggan', 'Nonaktifkan');

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            status: 'inactive',
        });
    });

    it('is a menu item for those who may read parties', async () => {
        mockApi(withPermissions(['parties.read']));

        renderApp(home);

        expect(
            await screen.findByRole('link', { name: 'Peran pihak' }),
        ).toBeInTheDocument();
    });

    it('offers no changes to someone who may only read', async () => {
        mockApi({
            ...withPermissions(['parties.read']),
            [`GET ${base}/party-roles`]: () => json({ data: [role()] }),
        });

        renderApp(path);

        await screen.findByText('Pelanggan');
        expect(
            screen.queryByRole('button', { name: 'Tambah peran' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Impor peran bawaan' }),
        ).not.toBeInTheDocument();
    });
});
