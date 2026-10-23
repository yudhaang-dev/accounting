import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

// What a formal consolidation needs from the screens (CO-FR-05, CO-FR-07, CO-FR-10): asking for a formal run
// and reading why it is refused, the elimination tolerance of the group, and the cash flow class of an account.

const groupId = '01JGROUP00000000000000001';
const base = `/api/v1/groups/${groupId}`;

const group = {
    id: groupId,
    name: 'Grup Acme',
    parent_company_id: workspace.company_id,
    presentation_currency: 'IDR',
    package_policy: 'draft',
    elimination_tolerance: '1000.5',
    lock_version: 3,
    abilities: ['read', 'run', 'approve'],
};

const groupAccount = (overrides: Record<string, unknown> = {}) => ({
    id: '01JGACC00000000000000001',
    code: '1000',
    name: 'Kas',
    class: 'asset',
    cash_flow_class: null,
    is_active: true,
    lock_version: 1,
    ...overrides,
});

const signedIn = {
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions: [] }] }),
    'GET /api/v1/groups': () => json({ data: [group] }),
};

describe('formal run (CO-FR-05)', () => {
    it('asks for a formal run and lists every reason it is refused', async () => {
        const api = mockApi({
            ...signedIn,
            [`GET ${base}/runs`]: () => json({ data: [] }),
            [`POST ${base}/runs`]: () =>
                json(
                    {
                        message: 'Invalid.',
                        code: 'VALIDATION_ERROR',
                        errors: {
                            formal: [
                                'Akun 1112 PT Anak belum dipetakan.',
                                'Pasangan PT Induk dan PT Anak selisih.',
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
        const dialog = await screen.findByRole('dialog');

        await user.click(
            within(dialog).getByRole('checkbox', {
                name: /Run formal/,
            }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Jalankan roll-up' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/runs`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/runs`)[0]?.body).toMatchObject({
            formal: true,
        });
        expect(
            await within(dialog).findByText(
                'Akun 1112 PT Anak belum dipetakan.',
            ),
        ).toBeTruthy();
        expect(
            within(dialog).getByText('Pasangan PT Induk dan PT Anak selisih.'),
        ).toBeTruthy();
    });
});

describe('elimination tolerance (CO-FR-07)', () => {
    it('shows the tolerance of the group and saves a new one as a decimal string', async () => {
        const api = mockApi({
            ...signedIn,
            [`GET ${base}/perimeter`]: () => json({ data: [] }),
            [`PATCH ${base}`]: () =>
                json({ data: { ...group, lock_version: 4 } }),
        });

        renderApp(`/groups/${groupId}/perimeter`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Pengaturan' }),
        );
        const dialog = await screen.findByRole('dialog');
        const tolerance = within(dialog).getByLabelText(/Toleransi eliminasi/);

        expect((tolerance as HTMLInputElement).value).toBe('1.000,5');

        await user.clear(tolerance);
        await user.type(tolerance, '2500');
        await user.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`PATCH ${base}`)).toHaveLength(1),
        );
        expect(api.called(`PATCH ${base}`)[0]?.body).toMatchObject({
            lock_version: 3,
            elimination_tolerance: '2500',
        });
    });
});

describe('cash flow class of a group account (CO-FR-10)', () => {
    it('offers cash only for assets, and sends the class with a new account', async () => {
        const api = mockApi({
            ...signedIn,
            [`GET ${base}/accounts`]: () =>
                json({
                    data: [groupAccount({ cash_flow_class: 'cash' })],
                }),
            [`POST ${base}/accounts`]: () =>
                json(
                    { data: groupAccount({ id: '01JGACC00000000000000002' }) },
                    201,
                ),
        });

        renderApp(`/groups/${groupId}/accounts`);
        const user = userEvent.setup();

        // The class of the existing account is listed.
        expect((await screen.findAllByText('Kas')).length).toBeGreaterThan(1);

        await user.click(screen.getByRole('button', { name: 'Akun baru' }));
        const dialog = await screen.findByRole('dialog');

        await user.type(within(dialog).getByLabelText('Kode'), '2000');
        await user.type(within(dialog).getByLabelText('Nama'), 'Utang');
        await user.click(
            within(dialog).getByRole('combobox', { name: 'Kelas' }),
        );
        await user.click(
            await screen.findByRole('option', { name: 'Liabilitas' }),
        );

        await user.click(
            within(dialog).getByRole('combobox', { name: 'Kelas arus kas' }),
        );
        expect(screen.queryByRole('option', { name: 'Kas' })).toBeNull();
        await user.click(
            await screen.findByRole('option', { name: 'Operasi' }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/accounts`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/accounts`)[0]?.body).toMatchObject({
            code: '2000',
            class: 'liability',
            cash_flow_class: 'operating',
        });
    });
});
