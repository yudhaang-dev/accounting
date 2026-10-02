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
const path = `/w/${workspace.company_id}/ledger-settings`;

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

const equityId = '01JACCOUNT0000000000000010';
const parentEquityId = '01JACCOUNT0000000000000011';
const incomeId = '01JACCOUNT0000000000000012';
const inactiveEquityId = '01JACCOUNT0000000000000013';

const accountRow = (overrides: Record<string, unknown>) => ({
    id: equityId,
    code: '3130',
    name: 'Saldo Laba',
    class: 'equity',
    normal_balance: 'credit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    allowed_sources: ['entry', 'subledger', 'system'],
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
        id: parentEquityId,
        code: '3000',
        name: 'EKUITAS',
        is_leaf: false,
    }),
    accountRow({
        id: inactiveEquityId,
        code: '3140',
        name: 'Lama',
        status: 'inactive',
    }),
    accountRow({
        id: incomeId,
        code: '7130',
        name: 'Laba Selisih Kurs',
        class: 'income',
    }),
];

const role = (key: string, overrides: Record<string, unknown> = {}) => ({
    key,
    account_id: null,
    effective_date: null,
    valid: false,
    required:
        key === 'retained_earnings' ? 'year_end_close' : 'foreign_currency',
    allowed_classes:
        key === 'retained_earnings' ? ['equity'] : ['income', 'expense'],
    lock_version: 0,
    ...overrides,
});

const roles = [
    role('retained_earnings'),
    role('fx_realized_gain'),
    role('fx_realized_loss'),
    role('fx_unrealized_gain', {
        account_id: incomeId,
        valid: true,
        lock_version: 2,
    }),
    role('fx_unrealized_loss'),
    role('fx_rounding'),
];

const policy = (overrides: Record<string, unknown> = {}) => ({
    version: 3,
    soft_closed_transaction_type_keys: ['audit_adjustment'],
    soft_closed_permission: null,
    source_override_permission: null,
    fx_rounding_tolerance: '0.00',
    revaluation_rate_type_key: null,
    revaluation_auto_reverse: false,
    lock_version: 3,
    ...overrides,
});

const routes = (permissions: string[]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/account-roles`]: () => json({ data: roles }),
    [`GET ${base}/accounts`]: () => list(chart),
    [`GET ${base}/ledger-policy`]: () => json({ data: policy() }),
    [`GET ${base}/transaction-types`]: () =>
        json({
            data: [
                { id: 't1', key: 'audit_adjustment', label: 'Koreksi Audit' },
                { id: 't2', key: 'general_journal', label: 'Jurnal Umum' },
            ],
        }),
    [`GET ${base}/permissions`]: () =>
        json({
            data: [
                {
                    key: 'journals.post-soft-closed',
                    resource: 'journals',
                    action: 'post-soft-closed',
                },
                {
                    key: 'journals.override-source',
                    resource: 'journals',
                    action: 'override-source',
                },
            ],
        }),
    [`GET ${base}/rate-types`]: () =>
        list([
            {
                id: 'r1',
                key: 'closing',
                label: 'Kurs penutup',
                status: 'active',
                lock_version: 1,
            },
        ]),
});

const manage = ['accounts.read', 'accounts.manage', 'policies.manage'];

describe('ledger settings: account roles (GL-FR-10)', () => {
    it('lists every role with whether it is mapped', async () => {
        mockApi(routes(['accounts.read']));

        renderApp(path);

        expect(await screen.findByText('Saldo laba')).toBeInTheDocument();
        expect(
            screen.getByText('Wajib sebelum tutup tahun.'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Belum dipetakan')).toHaveLength(5);
        expect(screen.getByText('Terpetakan')).toBeInTheDocument();
        // Nothing to change, and no policy form, without the permissions.
        expect(
            screen.queryByRole('button', { name: /^Simpan peran/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Kebijakan buku besar'),
        ).not.toBeInTheDocument();
    });

    it('maps a role to an account of the right class, as a new version', async () => {
        const put = `PUT ${base}/account-roles/retained_earnings`;
        const api = mockApi({
            ...routes(manage),
            [put]: () =>
                json({
                    data: {
                        key: 'retained_earnings',
                        account_id: equityId,
                        effective_date: null,
                        lock_version: 1,
                    },
                }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Saldo laba');
        await typist.click(
            screen.getByRole('combobox', { name: 'Saldo laba' }),
        );

        // Only an active leaf of the equity class qualifies: not the parent, the inactive one or the income account.
        expect(
            await screen.findByRole('option', { name: '3130 · Saldo Laba' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /EKUITAS/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /3140/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /7130/ }),
        ).not.toBeInTheDocument();

        await typist.click(
            screen.getByRole('option', { name: '3130 · Saldo Laba' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan peran Saldo laba' }),
        );

        await waitFor(() => expect(api.called(put)).toHaveLength(1));
        expect(api.called(put)[0]?.body).toEqual({
            lock_version: 0,
            account_id: equityId,
        });
    });

    it('reloads the roles when someone else mapped the role first', async () => {
        const put = `PUT ${base}/account-roles/retained_earnings`;
        const api = mockApi({
            ...routes(manage),
            [put]: () =>
                json({ message: 'Conflict', code: 'VERSION_CONFLICT' }, 409),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Saldo laba');
        await typist.click(
            screen.getByRole('combobox', { name: 'Saldo laba' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '3130 · Saldo Laba' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan peran Saldo laba' }),
        );

        // The notice is a toast; what the screen must do is load the newer mapping.
        await waitFor(() =>
            expect(
                api.called(`GET ${base}/account-roles`).length,
            ).toBeGreaterThan(1),
        );
    });

    it('sends someone without account permission back to the company home', async () => {
        mockApi(withPermissions([]));

        const { router } = renderApp(path);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });
});

describe('ledger settings: ledger policy', () => {
    const put = `PUT ${base}/ledger-policy`;

    it('shows the current policy and saves a change with its version', async () => {
        const api = mockApi({
            ...routes(manage),
            [put]: () =>
                json({ data: policy({ version: 4, lock_version: 4 }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        expect(
            await screen.findByRole('checkbox', { name: 'audit_adjustment' }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: 'system.closing' }),
        ).not.toBeChecked();
        expect(screen.getByText('Versi 3')).toBeInTheDocument();

        await typist.click(
            screen.getByRole('checkbox', { name: 'system.closing' }),
        );
        await typist.click(
            screen.getByRole('combobox', {
                name: 'Hak tambahan untuk posting ke periode tutup sementara',
            }),
        );
        await typist.click(
            await screen.findByRole('option', {
                name: 'journals.post-soft-closed',
            }),
        );
        await typist.click(
            screen.getByRole('combobox', {
                name: 'Jenis kurs penutup revaluasi',
            }),
        );
        await typist.click(
            await screen.findByRole('option', {
                name: 'closing · Kurs penutup',
            }),
        );
        await typist.click(
            screen.getByRole('checkbox', { name: /Balik revaluasi otomatis/ }),
        );
        const tolerance = screen.getByLabelText(
            'Toleransi pembulatan konversi kurs',
        );

        await typist.clear(tolerance);
        await typist.type(tolerance, '0.5');
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        await waitFor(() => expect(api.called(put)).toHaveLength(1));
        expect(api.called(put)[0]?.body).toEqual({
            lock_version: 3,
            soft_closed_transaction_type_keys: [
                'audit_adjustment',
                'system.closing',
            ],
            soft_closed_permission: 'journals.post-soft-closed',
            source_override_permission: null,
            fx_rounding_tolerance: 0.5,
            revaluation_rate_type_key: 'closing',
            revaluation_auto_reverse: true,
        });
    });

    it('will not save a tolerance that is not a decimal number', async () => {
        const api = mockApi(routes(manage));

        renderApp(path);
        const typist = userEvent.setup();

        const tolerance = await screen.findByLabelText(
            'Toleransi pembulatan konversi kurs',
        );

        await typist.clear(tolerance);
        await typist.type(tolerance, 'abc');
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(
            await screen.findByText(/Isi angka desimal tidak negatif/),
        ).toBeInTheDocument();
        expect(api.called(put)).toHaveLength(0);
    });

    it('shows the field the server refused and keeps the form', async () => {
        mockApi({
            ...routes(manage),
            [put]: () =>
                json(
                    {
                        message: 'Invalid',
                        code: 'VALIDATION_ERROR',
                        errors: {
                            soft_closed_permission: [
                                'Hak journals.xyz tidak ada.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByRole('checkbox', { name: 'audit_adjustment' });
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(
            await screen.findByText('Hak journals.xyz tidak ada.'),
        ).toBeInTheDocument();
    });

    it('reloads the newest version when someone else saved first', async () => {
        let calls = 0;
        const api = mockApi({
            ...routes(manage),
            [`GET ${base}/ledger-policy`]: () =>
                json({
                    data: policy(
                        calls++ === 0 ? {} : { version: 5, lock_version: 5 },
                    ),
                }),
            [put]: () =>
                json({ message: 'Conflict', code: 'VERSION_CONFLICT' }, 409),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByRole('checkbox', { name: 'audit_adjustment' });
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(await screen.findByText('Versi 5')).toBeInTheDocument();
        expect(api.called(`GET ${base}/ledger-policy`).length).toBeGreaterThan(
            1,
        );
    });

    it('is a section only for those who may manage policies', async () => {
        mockApi(routes(['accounts.read', 'accounts.manage']));

        renderApp(path);

        await screen.findByText('Saldo laba');
        expect(
            screen.queryByRole('heading', { name: 'Kebijakan buku besar' }),
        ).not.toBeInTheDocument();
        expect(
            within(screen.getByRole('main')).queryByLabelText(
                'Toleransi pembulatan konversi kurs',
            ),
        ).not.toBeInTheDocument();
    });
});
