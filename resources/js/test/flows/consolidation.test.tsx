import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

// Consolidation groups (CO-FR-02, CO-FR-04, CO-FR-09, CO-FR-10, IA-FR-07): the groups the user holds a grant on,
// the perimeter with its approval, the group chart, the mapping, and the management roll-up with its CSV.

const groupId = '01JGROUP00000000000000001';
const runId = '01JRUN00000000000000000001';
const childCompany = '01JCOMPANY00000000000000002';
const parentCompany = workspace.company_id;
const childWorkspace = {
    ...workspace,
    id: '01JWORKSPACE000000000000002',
    name: 'Toko Anak',
    company_id: childCompany,
    company_name: 'PT Anak',
};

const signedInAs = (permissions: string[], groups: unknown[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({
            data: [
                { ...workspace, permissions },
                { ...childWorkspace, permissions },
            ],
        }),
    'GET /api/v1/groups': () => json({ data: groups }),
});

const group = (
    abilities: string[],
    overrides: Record<string, unknown> = {},
) => ({
    id: groupId,
    name: 'Grup Acme',
    parent_company_id: parentCompany,
    presentation_currency: 'IDR',
    package_policy: 'draft',
    lock_version: 1,
    abilities,
    ...overrides,
});

const member = (overrides: Record<string, unknown> = {}) => ({
    id: '01JMEMBER0000000000000001',
    company_id: parentCompany,
    company_name: 'PT Toko Budi',
    control_basis: 'Perusahaan induk grup.',
    treatment: 'full',
    status: 'approved',
    effective_from: '2026-01-01',
    effective_to: null,
    proposed_by: 1,
    approved_by: 1,
    approved_at: '2026-01-01T00:00:00+00:00',
    ended_by: null,
    ended_at: null,
    ...overrides,
});

const groupAccount = (overrides: Record<string, unknown> = {}) => ({
    id: '01JGACC00000000000000001',
    code: '1000',
    name: 'Kas',
    class: 'asset',
    is_active: true,
    lock_version: 1,
    ...overrides,
});

const base = `/api/v1/groups/${groupId}`;
const full = ['read', 'run', 'approve'];

describe('groups (IA-FR-07)', () => {
    it('lists only the groups the user holds a grant on, with what they may do', async () => {
        mockApi(signedInAs([], [group(['read'])]));

        renderApp('/groups');

        const link = await screen.findByRole('link', { name: 'Grup Acme' });
        const row = link.closest('tr') as HTMLElement;

        expect(within(row).getByText('PT Toko Budi')).toBeTruthy();
        expect(within(row).getByText('Membaca')).toBeTruthy();
        expect(within(row).queryByText('Menjalankan')).toBeNull();
        // Without the consent of any company there is nothing to create a group from.
        expect(screen.queryByRole('button', { name: 'Grup baru' })).toBeNull();
    });

    it('explains an empty list, and offers to create one only to who may include a company', async () => {
        mockApi(signedInAs([], []));

        renderApp('/groups');

        expect(await screen.findByText('Belum ada grup')).toBeTruthy();
        expect(
            screen.getByText(
                /tidak memiliki izin untuk menyertakan perusahaan/,
            ),
        ).toBeTruthy();
    });

    it('creates a group with the parent company and currency, then opens its perimeter', async () => {
        // The list is the API's word on what the user holds a grant on: it has the group once it is created.
        const created: unknown[] = [];
        const api = mockApi({
            ...signedInAs(['consolidation.join'], []),
            'GET /api/v1/groups': () => json({ data: created }),
            'POST /api/v1/groups': () => {
                created.push(group(full));

                return json({ data: group(full) }, 201);
            },
            [`GET ${base}/perimeter`]: () => json({ data: [member()] }),
        });

        renderApp('/groups');
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Grup baru' }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Grup baru' });

        await user.type(
            within(dialog).getByLabelText('Nama grup'),
            'Grup Acme',
        );
        await user.click(
            within(dialog).getByRole('combobox', { name: 'Perusahaan induk' }),
        );
        await user.click(
            await screen.findByRole('option', { name: 'PT Toko Budi' }),
        );
        await user.type(
            within(dialog).getByLabelText('Mata uang penyajian'),
            'idr',
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Buat grup' }),
        );

        await waitFor(() =>
            expect(api.called('POST /api/v1/groups')).toHaveLength(1),
        );
        expect(api.called('POST /api/v1/groups')[0]?.body).toMatchObject({
            name: 'Grup Acme',
            parent_company_id: parentCompany,
            presentation_currency: 'IDR',
        });
        expect(
            await screen.findByRole('heading', { name: 'Grup Acme' }),
        ).toBeTruthy();
    });

    it('does not open a group the user holds no grant on', async () => {
        mockApi(signedInAs([], []));

        renderApp(`/groups/${groupId}/perimeter`);

        expect(await screen.findByText('Data tidak ditemukan')).toBeTruthy();
    });

    it('sets the package policy and the name with the lock version', async () => {
        const api = mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/perimeter`]: () => json({ data: [member()] }),
            [`PATCH ${base}`]: () =>
                json({
                    data: group(full, {
                        package_policy: 'reject',
                        lock_version: 2,
                    }),
                }),
        });

        renderApp(`/groups/${groupId}/perimeter`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Pengaturan' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Pengaturan grup',
        });
        await user.click(
            within(dialog).getByRole('combobox', {
                name: 'Paket yang belum ditutup atau belum lengkap',
            }),
        );
        await user.click(
            await screen.findByRole('option', {
                name: 'Paket belum lengkap: tolak',
            }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`PATCH ${base}`)).toHaveLength(1),
        );
        expect(api.called(`PATCH ${base}`)[0]?.body).toMatchObject({
            lock_version: 1,
            name: 'Grup Acme',
            package_policy: 'reject',
        });
    });
});

describe('perimeter (CO-FR-02)', () => {
    const pending = member({
        id: '01JMEMBER0000000000000002',
        company_id: childCompany,
        company_name: 'PT Anak',
        control_basis: 'Mayoritas hak suara.',
        status: 'pending',
        approved_by: null,
        approved_at: null,
    });

    it('shows each member with its status and the reasoning of the assessment', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/perimeter`]: () =>
                json({ data: [member(), pending] }),
        });

        renderApp(`/groups/${groupId}/perimeter`);

        expect(await screen.findByText('Mayoritas hak suara.')).toBeTruthy();
        const row = screen.getByText('PT Anak').closest('tr') as HTMLElement;

        expect(within(row).getByText('Menunggu persetujuan')).toBeTruthy();
        expect(screen.getByText('Induk')).toBeTruthy();
        // A reader sees the perimeter but cannot change or approve anything.
        expect(screen.queryByRole('button', { name: 'Setujui' })).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Ajukan anggota' }),
        ).toBeNull();
    });

    it('approves a member who is waiting', async () => {
        let approved = false;
        const api = mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/perimeter`]: () =>
                json({
                    data: [
                        member(),
                        approved
                            ? { ...pending, status: 'approved', approved_by: 2 }
                            : pending,
                    ],
                }),
            [`POST ${base}/perimeter/${pending.id}/approve`]: () => {
                approved = true;

                return json({
                    data: { ...pending, status: 'approved', approved_by: 2 },
                });
            },
        });

        renderApp(`/groups/${groupId}/perimeter`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Setujui' }),
        );

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/perimeter/${pending.id}/approve`),
            ).toHaveLength(1),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Setujui' }),
            ).toBeNull(),
        );
    });

    it('shows why the proposer cannot approve their own proposal', async () => {
        mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/perimeter`]: () =>
                json({ data: [member(), pending] }),
            [`POST ${base}/perimeter/${pending.id}/approve`]: () =>
                json(
                    {
                        message: 'Penilaian harus disetujui orang lain.',
                        code: 'SEGREGATION_OF_DUTIES',
                    },
                    403,
                ),
        });

        renderApp(`/groups/${groupId}/perimeter`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Setujui' }),
        );

        expect((await screen.findByRole('alert')).textContent).toContain(
            'Penilaian harus disetujui orang lain.',
        );
    });

    it('proposes a company the user may include, with the basis of control', async () => {
        const api = mockApi({
            ...signedInAs(['consolidation.join'], [group(full)]),
            [`GET ${base}/perimeter`]: () => json({ data: [member()] }),
            [`POST ${base}/perimeter`]: () =>
                json(
                    {
                        data: {
                            id: pending.id,
                            company_id: childCompany,
                            status: 'pending',
                        },
                    },
                    201,
                ),
        });

        renderApp(`/groups/${groupId}/perimeter`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Ajukan anggota' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Ajukan perusahaan sebagai anggota',
        });

        // The parent is already a member, so only the other company is offered, and it is chosen already.
        expect(
            within(dialog).getByRole('combobox', { name: 'Perusahaan' }),
        ).toBeTruthy();
        await user.type(
            within(dialog).getByLabelText('Dasar penilaian pengendalian'),
            'Mayoritas hak suara.',
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Ajukan anggota' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/perimeter`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/perimeter`)[0]?.body).toMatchObject({
            company_id: childCompany,
            control_basis: 'Mayoritas hak suara.',
        });
    });

    it('removes a member as of a date, but never the parent', async () => {
        const api = mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/perimeter`]: () =>
                json({ data: [member(), { ...pending, status: 'approved' }] }),
            [`POST ${base}/perimeter/${pending.id}/end`]: () =>
                json({
                    data: {
                        ...pending,
                        status: 'ended',
                        effective_to: '2026-06-30',
                    },
                }),
        });

        renderApp(`/groups/${groupId}/perimeter`);
        const user = userEvent.setup();

        await screen.findByText('PT Anak');
        // Only one row offers to remove: the parent has no such button.
        const buttons = screen.getAllByRole('button', { name: 'Keluarkan' });
        expect(buttons).toHaveLength(1);

        await user.click(buttons[0] as HTMLElement);
        const dialog = await screen.findByRole('dialog', {
            name: 'Keluarkan PT Anak dari perimeter',
        });
        await user.click(
            within(dialog).getByRole('button', { name: 'Keluarkan' }),
        );

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/perimeter/${pending.id}/end`),
            ).toHaveLength(1),
        );
        expect(
            api.called(`POST ${base}/perimeter/${pending.id}/end`)[0]?.body,
        ).toHaveProperty('effective_to');
    });
});

describe('group chart (CO-FR-04)', () => {
    it('creates an account, with its class', async () => {
        const accounts: unknown[] = [];
        const api = mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/accounts`]: () => json({ data: accounts }),
            [`POST ${base}/accounts`]: () => {
                accounts.push(groupAccount());

                return json({ data: groupAccount() }, 201);
            },
        });

        renderApp(`/groups/${groupId}/accounts`);
        const user = userEvent.setup();

        expect(await screen.findByText('Belum ada akun grup')).toBeTruthy();
        await user.click(screen.getByRole('button', { name: 'Akun baru' }));
        const dialog = await screen.findByRole('dialog', {
            name: 'Akun grup baru',
        });

        await user.type(within(dialog).getByLabelText('Kode'), '1000');
        await user.type(within(dialog).getByLabelText('Nama'), 'Kas');
        await user.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/accounts`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/accounts`)[0]?.body).toEqual({
            code: '1000',
            name: 'Kas',
            class: 'asset',
        });
        expect(await screen.findByText('Kas')).toBeTruthy();
    });

    it('renames an account with its lock version, and shows a duplicate code next to the field', async () => {
        const api = mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/accounts`]: () => json({ data: [groupAccount()] }),
            [`PATCH ${base}/accounts/${groupAccount().id}`]: () =>
                json({
                    data: groupAccount({
                        name: 'Kas dan bank',
                        lock_version: 2,
                    }),
                }),
            [`POST ${base}/accounts`]: () =>
                json(
                    {
                        message: 'Invalid',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            code: ['Grup sudah memiliki akun dengan kode ini.'],
                        },
                    },
                    422,
                ),
        });

        renderApp(`/groups/${groupId}/accounts`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Aksi untuk Kas' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Ubah akun grup',
        });
        const name = within(dialog).getByLabelText('Nama');

        await user.clear(name);
        await user.type(name, 'Kas dan bank');
        await user.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(
                api.called(`PATCH ${base}/accounts/${groupAccount().id}`),
            ).toHaveLength(1),
        );
        expect(
            api.called(`PATCH ${base}/accounts/${groupAccount().id}`)[0]?.body,
        ).toMatchObject({
            lock_version: 1,
            name: 'Kas dan bank',
            is_active: true,
        });

        await user.click(screen.getByRole('button', { name: 'Akun baru' }));
        const create = await screen.findByRole('dialog', {
            name: 'Akun grup baru',
        });
        await user.type(within(create).getByLabelText('Kode'), '1000');
        await user.type(within(create).getByLabelText('Nama'), 'Lagi');
        await user.click(
            within(create).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(create).findByText(
                'Grup sudah memiliki akun dengan kode ini.',
            ),
        ).toBeTruthy();
    });

    it('is read only without the run ability', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/accounts`]: () => json({ data: [groupAccount()] }),
        });

        renderApp(`/groups/${groupId}/accounts`);

        await screen.findByText('Kas');
        expect(screen.queryByRole('button', { name: 'Akun baru' })).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Aksi untuk Kas' }),
        ).toBeNull();
    });
});

describe('account mapping (CO-FR-04, P-04)', () => {
    const equity = groupAccount({
        id: '01JGACC00000000000000002',
        code: '3000',
        name: 'Ekuitas',
        class: 'equity',
    });
    const memberAccounts = (rows: unknown[]) => ({
        [`GET ${base}/member-accounts`]: () => json({ data: rows }),
    });
    const bankAccount = {
        id: '01JACC0000000000000000001',
        code: '1112',
        name: 'Bank BCA',
        class: 'asset',
    };
    const shareCapital = {
        id: '01JACC0000000000000000002',
        code: '3110',
        name: 'Modal disetor',
        class: 'equity',
    };
    const existing = {
        company_id: parentCompany,
        account_id: shareCapital.id,
        account_code: '3110',
        group_account_id: equity.id,
    };

    const routes = (mapping: unknown, abilities = full) => ({
        ...signedInAs([], [group(abilities)]),
        [`GET ${base}/perimeter`]: () => json({ data: [member()] }),
        [`GET ${base}/accounts`]: () =>
            json({ data: [groupAccount(), equity] }),
        [`GET ${base}/account-mappings`]: () => json({ data: mapping }),
        ...memberAccounts([bankAccount, shareCapital]),
    });

    it('offers only group accounts of the same class, and saves the whole mapping as a new version', async () => {
        const api = mockApi({
            ...routes({
                version: 1,
                effective_date: '2026-01-01',
                created_by: 1,
                mappings: [existing],
            }),
            [`PUT ${base}/account-mappings`]: () =>
                json({
                    data: {
                        version: 2,
                        effective_date: '2026-10-22',
                        created_by: 1,
                        mappings: [],
                    },
                }),
        });

        renderApp(`/groups/${groupId}/mapping`);
        const user = userEvent.setup();

        await screen.findByText('Bank BCA');
        expect(screen.getByText('Versi 1')).toBeTruthy();
        expect(screen.getByText('1 akun dipetakan')).toBeTruthy();
        expect(
            screen
                .getByRole('button', { name: 'Simpan versi baru' })
                .hasAttribute('disabled'),
        ).toBe(true);

        await user.click(
            screen.getByRole('combobox', { name: 'Akun grup untuk akun 1112' }),
        );
        // The equity account is not offered for an asset account.
        expect(
            screen.queryByRole('option', { name: '3000 · Ekuitas' }),
        ).toBeNull();
        await user.click(
            await screen.findByRole('option', { name: '1000 · Kas' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan versi baru' }),
        );

        await waitFor(() =>
            expect(api.called(`PUT ${base}/account-mappings`)).toHaveLength(1),
        );
        const body = api.called(`PUT ${base}/account-mappings`)[0]?.body as {
            lock_version: number;
            mappings: { account_id: string; group_account_id: string }[];
        };

        expect(body.lock_version).toBe(1);
        // What was already mapped is sent again: the version holds the whole mapping.
        expect(body.mappings).toHaveLength(2);
        expect(body.mappings).toContainEqual({
            company_id: parentCompany,
            account_id: bankAccount.id,
            group_account_id: groupAccount().id,
        });
        expect(body.mappings).toContainEqual({
            company_id: parentCompany,
            account_id: shareCapital.id,
            group_account_id: equity.id,
        });
    });

    it('sends version 0 for the first mapping, and drops an account set back to not mapped', async () => {
        const api = mockApi({
            ...routes({
                version: 0,
                effective_date: null,
                created_by: null,
                mappings: [],
            }),
            [`PUT ${base}/account-mappings`]: () =>
                json({
                    data: {
                        version: 1,
                        effective_date: '2026-10-22',
                        created_by: 1,
                        mappings: [],
                    },
                }),
        });

        renderApp(`/groups/${groupId}/mapping`);
        const user = userEvent.setup();

        await screen.findByText('Bank BCA');
        expect(screen.getByText('Belum ada versi')).toBeTruthy();

        await user.click(
            screen.getByRole('combobox', { name: 'Akun grup untuk akun 1112' }),
        );
        await user.click(
            await screen.findByRole('option', { name: '1000 · Kas' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan versi baru' }),
        );

        await waitFor(() =>
            expect(api.called(`PUT ${base}/account-mappings`)).toHaveLength(1),
        );
        expect(
            (
                api.called(`PUT ${base}/account-mappings`)[0]!.body as {
                    lock_version: number;
                }
            ).lock_version,
        ).toBe(0);
    });

    it('says when someone else saved a version in the meantime', async () => {
        mockApi({
            ...routes({
                version: 1,
                effective_date: '2026-01-01',
                created_by: 1,
                mappings: [existing],
            }),
            [`PUT ${base}/account-mappings`]: () =>
                json({ message: 'conflict', code: 'VERSION_CONFLICT' }, 409),
        });

        renderApp(`/groups/${groupId}/mapping`);
        const user = userEvent.setup();

        await screen.findByText('Bank BCA');
        await user.click(
            screen.getByRole('combobox', { name: 'Akun grup untuk akun 1112' }),
        );
        await user.click(
            await screen.findByRole('option', { name: '1000 · Kas' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan versi baru' }),
        );

        expect((await screen.findByRole('alert')).textContent).toContain(
            'Pemetaan sudah diubah orang lain',
        );
    });

    it('cannot be changed without the run ability', async () => {
        mockApi(
            routes(
                {
                    version: 1,
                    effective_date: '2026-01-01',
                    created_by: 1,
                    mappings: [existing],
                },
                ['read'],
            ),
        );

        renderApp(`/groups/${groupId}/mapping`);

        await screen.findByText('Bank BCA');
        expect(
            screen.queryByRole('button', { name: 'Simpan versi baru' }),
        ).toBeNull();
        expect(
            screen
                .getByRole('combobox', { name: 'Akun grup untuk akun 1112' })
                .hasAttribute('disabled'),
        ).toBe(true);
    });
});

const entity = (overrides: Record<string, unknown> = {}) => ({
    company_id: parentCompany,
    name: 'PT Toko Budi',
    functional_currency: 'IDR',
    book_status: 'closed',
    coverage_from: '2026-01-01',
    coverage_to: '2026-01-31',
    coverage_granularity: 'transactions',
    coverage_status: 'complete',
    balanced: true,
    source: {},
    translation: null,
    ...overrides,
});

const figures = (overrides: Record<string, string> = {}) => ({
    opening_debit: '0.00',
    opening_credit: '0.00',
    debit: '1300.00',
    credit: '50.00',
    closing_debit: '1250.00',
    closing_credit: '0.00',
    ...overrides,
});

const run = (overrides: Record<string, unknown> = {}) => ({
    id: runId,
    group_id: groupId,
    from: '2026-01-01',
    to: '2026-01-31',
    cutoff_date: '2026-01-31',
    label: 'management_rollup',
    formal: false,
    status: 'complete',
    presentation_currency: 'IDR',
    mapping_version: 1,
    translation_policy_version: null,
    package_policy: 'draft',
    elimination_tolerance: '0.00',
    coverage_status: 'complete',
    created_by: 1,
    created_at: '2026-02-01T01:00:00+00:00',
    perimeter: [],
    entities: [entity(), entity({ company_id: childCompany, name: 'PT Anak' })],
    lines: [
        {
            group_account_id: groupAccount().id,
            code: '1000',
            name: 'Kas',
            class: 'asset',
            cash_flow_class: null,
            ...figures(),
            closing: '1250.00',
            eliminations: [],
            adjustments: [],
            sources: [
                {
                    company_id: parentCompany,
                    account_id: '01JACC0000000000000000001',
                    code: '1112',
                    name: 'Bank BCA',
                    class: 'asset',
                    ...figures({
                        debit: '1000.00',
                        credit: '0.00',
                        closing_debit: '1000.00',
                    }),
                    closing: '1000.00',
                    kind: 'account',
                    translation: null,
                },
                {
                    company_id: childCompany,
                    account_id: '01JACC0000000000000000009',
                    code: '1111',
                    name: 'Kas Kecil',
                    class: 'asset',
                    ...figures({
                        debit: '300.00',
                        credit: '50.00',
                        closing_debit: '250.00',
                    }),
                    closing: '250.00',
                    kind: 'account',
                    translation: null,
                },
            ],
        },
    ],
    exceptions: [
        {
            company_id: childCompany,
            account_id: '01JACC0000000000000000010',
            code: '6220',
            name: 'Beban Sewa',
            class: 'expense',
            ...figures({
                debit: '50.00',
                credit: '0.00',
                closing_debit: '50.00',
            }),
            reason: 'unmapped',
            kind: 'account',
            translation: null,
        },
    ],
    intercompany: [],
    unassigned_eliminations: [],
    adjustments: [],
    totals: {
        eliminations: figures({
            debit: '0.00',
            credit: '0.00',
            closing_debit: '0.00',
            closing_credit: '0.00',
        }),
        adjustments: figures({
            debit: '0.00',
            credit: '0.00',
            closing_debit: '0.00',
            closing_credit: '0.00',
        }),
        mapped: figures(),
        unmapped: figures({
            debit: '50.00',
            credit: '0.00',
            closing_debit: '50.00',
        }),
        all: figures({
            debit: '1350.00',
            credit: '1350.00',
            closing_debit: '1300.00',
            closing_credit: '1300.00',
        }),
    },
    balanced: true,
    ...overrides,
});

const summary = (overrides: Record<string, unknown> = {}) =>
    Object.fromEntries(
        Object.entries(run(overrides)).filter(
            ([key]) =>
                ![
                    'perimeter',
                    'entities',
                    'lines',
                    'exceptions',
                    'intercompany',
                    'unassigned_eliminations',
                    'adjustments',
                    'totals',
                    'balanced',
                ].includes(key),
        ),
    );

describe('management roll-up (CO-FR-04, CO-FR-09, CO-FR-10)', () => {
    it('lists the runs newest first with their status and mapping version', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/runs`]: () =>
                json({
                    data: [
                        summary(),
                        summary({
                            id: '01JRUN00000000000000000002',
                            status: 'draft',
                            mapping_version: null,
                        }),
                    ],
                    meta: {},
                }),
        });

        renderApp(`/groups/${groupId}/runs`);

        const rows = await screen.findAllByRole('row');

        expect(rows).toHaveLength(3);
        expect(
            within(rows[1] as HTMLElement).getByText('Lengkap', {
                selector: 'span',
            }),
        ).toBeTruthy();
        expect(within(rows[2] as HTMLElement).getByText('Draf')).toBeTruthy();
        // A reader sees the runs but cannot start one.
        expect(
            screen.queryByRole('button', { name: 'Jalankan roll-up' }),
        ).toBeNull();
    });

    it('runs the roll-up for a range and opens the result', async () => {
        const api = mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/runs`]: () => json({ data: [], meta: {} }),
            [`POST ${base}/runs`]: () => json({ data: run() }, 201),
            [`GET ${base}/runs/${runId}`]: () => json({ data: run() }),
        });

        renderApp(`/groups/${groupId}/runs`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Jalankan roll-up' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Jalankan roll-up manajemen',
        });
        await user.click(
            within(dialog).getByRole('button', { name: 'Jalankan roll-up' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/runs`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/runs`)[0]?.body).toHaveProperty('from');
        expect(api.called(`POST ${base}/runs`)[0]?.body).toHaveProperty('to');
        expect(await screen.findByText('Roll-up manajemen')).toBeTruthy();
    });

    it('shows every reason a run is refused, naming the companies', async () => {
        mockApi({
            ...signedInAs([], [group(full)]),
            [`GET ${base}/runs`]: () => json({ data: [], meta: {} }),
            [`POST ${base}/runs`]: () =>
                json(
                    {
                        message: 'Invalid',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            [`entities.${parentCompany}`]: [
                                'Mata uang fungsional USD berbeda dari mata uang penyajian IDR.',
                            ],
                            [`entities.${childCompany}`]: [
                                'Buku perusahaan belum ditutup untuk rentang ini (open).',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(`/groups/${groupId}/runs`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Jalankan roll-up' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Jalankan roll-up manajemen',
        });
        await user.click(
            within(dialog).getByRole('button', { name: 'Jalankan roll-up' }),
        );

        const alert = await within(dialog).findByRole('alert');

        expect(alert.textContent).toContain('Mata uang fungsional USD berbeda');
        expect(alert.textContent).toContain('belum ditutup');
    });

    it('labels the result as a roll-up that is not a formal statement, with entities, lines and exceptions', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/runs/${runId}`]: () => json({ data: run() }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);

        expect(await screen.findByText('Roll-up manajemen')).toBeTruthy();
        expect(
            screen.getByText('Bukan laporan konsolidasian formal'),
        ).toBeTruthy();
        expect(screen.getByText('Perusahaan yang ikut')).toBeTruthy();
        expect(screen.getAllByText('PT Anak').length).toBeGreaterThan(0);

        // The group line adds the two companies; the unmapped expense is an exception, not lost.
        const exceptions = screen.getByRole('region', {
            name: 'Pengecualian: akun tanpa pemetaan',
        });
        expect(within(exceptions).getByText('Beban Sewa')).toBeTruthy();
        expect(screen.queryByText('Draf')).toBeNull();
        expect(
            screen.queryByText(/Total debit dan kredit tidak sama/),
        ).toBeNull();
    });

    it('opens a group line to the company accounts behind it', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/runs/${runId}`]: () => json({ data: run() }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);
        const user = userEvent.setup();

        await screen.findByText('Roll-up manajemen');
        expect(screen.queryByText('1112')).toBeNull();

        await user.click(
            screen.getByRole('button', {
                name: 'Rincian akun perusahaan untuk Kas',
            }),
        );

        expect(screen.getByText('1112')).toBeTruthy();
        expect(screen.getByText('1111')).toBeTruthy();
        expect(screen.getByText(/Bank BCA · PT Toko Budi/)).toBeTruthy();
        expect(screen.getByText(/Kas Kecil · PT Anak/)).toBeTruthy();
    });

    it('flags a draft and a result that does not balance', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/runs/${runId}`]: () =>
                json({
                    data: run({
                        status: 'draft',
                        balanced: false,
                        entities: [
                            entity({
                                book_status: 'open',
                                coverage_status: 'partial',
                            }),
                        ],
                    }),
                }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);

        expect(
            await screen.findByText(/Ada paket yang belum ditutup bukunya/),
        ).toBeTruthy();
        expect(
            screen
                .getAllByRole('alert')
                .some((alert) =>
                    alert.textContent?.includes(
                        'Total debit dan kredit tidak sama',
                    ),
                ),
        ).toBe(true);
        expect(screen.getByText('Terbuka')).toBeTruthy();
        expect(screen.getByText('Sebagian')).toBeTruthy();
    });

    it('exports the same run as CSV', async () => {
        let downloaded: string | undefined;

        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                downloaded = this.download;
            },
        );

        const api = mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/runs/${runId}`]: (call) =>
                call.accept?.includes('text/csv')
                    ? new Response('type,code\n', {
                          headers: {
                              'Content-Type': 'text/csv',
                              'Content-Disposition':
                                  'attachment; filename="management_rollup_2026-01-01_2026-01-31.csv"',
                          },
                      })
                    : json({ data: run() }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);
        const user = userEvent.setup();

        await screen.findByText('Roll-up manajemen');
        await user.click(screen.getByRole('button', { name: 'Ekspor CSV' }));

        await waitFor(() =>
            expect(downloaded).toBe(
                'management_rollup_2026-01-01_2026-01-31.csv',
            ),
        );
        expect(
            api
                .called(`GET ${base}/runs/${runId}`)
                .some((call) => call.accept?.includes('text/csv')),
        ).toBe(true);
    });

    it('does not open a run that is not there', async () => {
        mockApi({
            ...signedInAs([], [group(['read'])]),
            [`GET ${base}/runs/${runId}`]: () =>
                json({ message: 'Not found', code: 'NOT_FOUND' }, 404),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);

        expect(await screen.findByText('Data tidak ditemukan')).toBeTruthy();
    });
});
