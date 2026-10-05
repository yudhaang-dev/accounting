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
const path = `${home}/parties`;

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

const partyId = '01JPARTY00000000000000001';
const profileId = '01JPROFILE000000000000001';

const party = (overrides: Record<string, unknown> = {}) => ({
    id: partyId,
    name: 'PT Maju Jaya',
    is_customer: true,
    is_supplier: false,
    tax_id: '01.234.567.8-901.000',
    posting_profile_id: null,
    status: 'active',
    external_refs: [{ source_system: 'erp', external_ref: 'C-100' }],
    lock_version: 1,
    ...overrides,
});

const profiles = () =>
    json({
        data: [
            {
                id: profileId,
                name: 'Pihak berelasi',
                is_default: false,
                lock_version: 1,
                accounts: [],
            },
        ],
    });

const manager = [
    'parties.read',
    'parties.manage',
    'posting-profiles.read',
];

describe('parties', () => {
    it('lists parties with roles, references, and the profile', async () => {
        mockApi({
            ...withPermissions(manager),
            [`GET ${base}/parties`]: () =>
                list([
                    party(),
                    party({
                        id: '01JPARTY00000000000000002',
                        name: 'CV Sentosa',
                        is_customer: false,
                        is_supplier: true,
                        tax_id: null,
                        posting_profile_id: profileId,
                        external_refs: [],
                    }),
                ]),
            [`GET ${base}/posting-profiles`]: profiles,
        });

        renderApp(path);

        expect(await screen.findByText('PT Maju Jaya')).toBeInTheDocument();
        expect(screen.getByText('C-100')).toBeInTheDocument();
        expect(screen.getByText('CV Sentosa')).toBeInTheDocument();
        expect(await screen.findByText('Pihak berelasi')).toBeInTheDocument();
        expect(screen.getByText('Profil default')).toBeInTheDocument();
    });

    it('sends the search to the API', async () => {
        const index = `GET ${base}/parties`;
        const api = mockApi({
            ...withPermissions(manager),
            [index]: () => list([party()]),
            [`GET ${base}/posting-profiles`]: profiles,
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('PT Maju Jaya');
        await typist.type(screen.getByLabelText('Cari pihak'), 'maju');

        await waitFor(() =>
            expect(
                api
                    .called(index)
                    .some((call) => call.query['filter[search]'] === 'maju'),
            ).toBe(true),
        );
    });

    it('adds a party with a role and an external reference', async () => {
        const create = `POST ${base}/parties`;
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/parties`]: () => list([]),
            [`GET ${base}/posting-profiles`]: profiles,
            [create]: () => json({ data: party() }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            (await screen.findAllByRole('button', { name: 'Tambah pihak' }))[0]!,
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'PT Maju Jaya');
        await typist.click(within(dialog).getByRole('checkbox', { name: 'Pemasok' }));
        await typist.click(
            within(dialog).getByRole('button', { name: 'Tambah referensi' }),
        );
        await typist.type(
            within(dialog).getByLabelText(/^Sistem sumber/),
            'erp',
        );
        await typist.type(within(dialog).getByLabelText(/^Referensi, /), 'C-100');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'PT Maju Jaya',
            is_customer: true,
            is_supplier: true,
            tax_id: null,
            posting_profile_id: null,
            external_refs: [{ source_system: 'erp', external_ref: 'C-100' }],
        });
    });

    it('does not save a party without a role', async () => {
        const create = `POST ${base}/parties`;
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/parties`]: () => list([]),
            [`GET ${base}/posting-profiles`]: profiles,
            [create]: () => json({ data: party() }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            (await screen.findAllByRole('button', { name: 'Tambah pihak' }))[0]!,
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'PT Maju Jaya');
        await typist.click(within(dialog).getByRole('checkbox', { name: 'Pelanggan' }));

        expect(
            await within(dialog).findByText('Pilih sedikitnya satu peran.'),
        ).toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );
        expect(api.called(create)).toHaveLength(0);
    });

    it('shows a reference taken by another party next to its field', async () => {
        const create = `POST ${base}/parties`;
        mockApi({
            ...withPermissions(manager),
            [`GET ${base}/parties`]: () => list([]),
            [`GET ${base}/posting-profiles`]: profiles,
            [create]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'external_refs.0.external_ref': [
                                'Referensi C-100 sudah dipakai pihak lain.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            (await screen.findAllByRole('button', { name: 'Tambah pihak' }))[0]!,
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'PT Maju Jaya');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Tambah referensi' }),
        );
        await typist.type(
            within(dialog).getByLabelText(/^Sistem sumber/),
            'erp',
        );
        await typist.type(within(dialog).getByLabelText(/^Referensi, /), 'C-100');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText(
                'Referensi C-100 sudah dipakai pihak lain.',
            ),
        ).toBeInTheDocument();
    });

    it('edits a party, resending only new references with the lock version', async () => {
        const update = `PATCH ${base}/parties/${partyId}`;
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/parties`]: () => list([party()]),
            [`GET ${base}/posting-profiles`]: profiles,
            [update]: () => json({ data: party({ name: 'PT Maju Makmur' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('PT Maju Jaya');
        await rowAction(typist, 'PT Maju Jaya', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog).getByRole('list', {
                name: 'Referensi yang sudah ada',
            }),
        ).toHaveTextContent('C-100');

        await typist.clear(within(dialog).getByLabelText('Nama'));
        await typist.type(
            within(dialog).getByLabelText('Nama'),
            'PT Maju Makmur',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            name: 'PT Maju Makmur',
            is_customer: true,
            is_supplier: false,
            tax_id: '01.234.567.8-901.000',
            posting_profile_id: null,
            external_refs: [],
        });
    });

    it('leaves the profile alone without permission to list profiles', async () => {
        const update = `PATCH ${base}/parties/${partyId}`;
        const api = mockApi({
            ...withPermissions(['parties.read', 'parties.manage']),
            [`GET ${base}/parties`]: () =>
                list([party({ posting_profile_id: profileId })]),
            [update]: () => json({ data: party() }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('PT Maju Jaya');
        await rowAction(typist, 'PT Maju Jaya', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog).queryByLabelText('Profil posting'),
        ).not.toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).not.toHaveProperty(
            'posting_profile_id',
        );
    });

    it('asks before deactivating and reactivates without asking', async () => {
        const deactivate = `POST ${base}/parties/${partyId}/deactivate`;
        const reactivate = `POST ${base}/parties/${partyId}/reactivate`;
        let status = 'active';
        const api = mockApi({
            ...withPermissions(manager),
            [`GET ${base}/parties`]: () => list([party({ status })]),
            [`GET ${base}/posting-profiles`]: profiles,
            [deactivate]: () => {
                status = 'inactive';

                return json({ data: party({ status }) });
            },
            [reactivate]: () => {
                status = 'active';

                return json({ data: party({ status }) });
            },
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('PT Maju Jaya');
        await rowAction(typist, 'PT Maju Jaya', 'Nonaktifkan');

        expect(api.called(deactivate)).toHaveLength(0);
        await confirmWith(typist, 'Nonaktifkan');
        await waitFor(() => expect(api.called(deactivate)).toHaveLength(1));

        await rowAction(typist, 'PT Maju Jaya', 'Aktifkan');
        await waitFor(() => expect(api.called(reactivate)).toHaveLength(1));
    });

    it('offers no changes to someone who may only read', async () => {
        mockApi({
            ...withPermissions(['parties.read']),
            [`GET ${base}/parties`]: () => list([party()]),
        });

        renderApp(path);

        await screen.findByText('PT Maju Jaya');
        expect(
            screen.queryByRole('button', { name: 'Tambah pihak' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Aksi untuk PT Maju Jaya' }),
        ).not.toBeInTheDocument();
    });

    it('is a menu item only for those who may read parties', async () => {
        mockApi(withPermissions(['parties.read']));

        renderApp(home);

        expect(
            await screen.findByRole('link', { name: 'Pihak' }),
        ).toBeInTheDocument();
    });
});
