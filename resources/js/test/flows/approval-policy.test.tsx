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

type Routes = Parameters<typeof mockApi>[0];

const base = `/api/v1/companies/${workspace.company_id}`;
const policyPath = `/w/${workspace.company_id}/approval-policy`;

const withPermissions = (permissions: string[]): Routes => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const policy = (overrides: Record<string, unknown> = {}) => ({
    version: 0,
    separation_of_duties: false,
    rules: [],
    lock_version: 0,
    ...overrides,
});

const rule = (overrides: Record<string, unknown> = {}) => ({
    transaction_type_key: null,
    source: null,
    threshold: null,
    stages: ['entries.approve'],
    auto_post: false,
    ...overrides,
});

const routes = (current = policy(), overrides: Routes = {}): Routes => ({
    ...withPermissions(['policies.manage']),
    [`GET ${base}/approval-policy`]: () => json({ data: current }),
    [`GET ${base}/permissions`]: () =>
        json({
            data: [
                {
                    key: 'entries.approve',
                    resource: 'entries',
                    action: 'approve',
                },
                { key: 'entries.post', resource: 'entries', action: 'post' },
            ],
        }),
    [`GET ${base}/transaction-types`]: () =>
        json({
            data: [
                {
                    id: '01JTYPE000000000000000001',
                    key: 'payroll_summary',
                    label: 'Ringkasan Payroll',
                    numbering: {
                        pattern: 'PAY/{seq}',
                        padding: 4,
                        reset: 'never',
                        policy: 'on_create',
                    },
                    versions: [],
                    lock_version: 1,
                },
            ],
        }),
    ...overrides,
});

describe('approval policy', () => {
    it('says what happens when no policy was ever set', async () => {
        mockApi(routes());

        renderApp(policyPath);

        expect(await screen.findByText(/Belum ada aturan/)).toBeInTheDocument();
        expect(
            screen.getByText(/Entri yang tidak cocok dengan aturan mana pun/),
        ).toBeInTheDocument();
    });

    it('sets two approvals above a threshold with separation of duties (TE-AC-05)', async () => {
        const save = `PUT ${base}/approval-policy`;
        const api = mockApi(
            routes(policy(), {
                [save]: () =>
                    json({
                        data: policy({
                            version: 1,
                            lock_version: 1,
                            separation_of_duties: true,
                            rules: [
                                rule({
                                    threshold: '10000000',
                                    stages: [
                                        'entries.approve',
                                        'entries.approve',
                                    ],
                                }),
                            ],
                        }),
                    }),
            }),
        );

        renderApp(policyPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', {
                name: /Pembuat entri tidak boleh menyetujui/,
            }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Tambah aturan' }),
        );
        await typist.type(screen.getByLabelText('Di atas nominal'), '10000000');
        await typist.click(
            screen.getByRole('button', { name: 'Tambah tahap' }),
        );

        expect(
            screen.getByText(
                'Semua jenis · di atas 10.000.000 → 2 tahap persetujuan',
            ),
        ).toBeInTheDocument();

        await typist.click(
            screen.getByRole('button', { name: 'Simpan kebijakan' }),
        );

        await waitFor(() => expect(api.called(save)).toHaveLength(1));
        expect(api.called(save)[0]?.body).toEqual({
            lock_version: 0,
            separation_of_duties: true,
            rules: [
                {
                    transaction_type_key: null,
                    source: null,
                    threshold: '10000000',
                    stages: ['entries.approve', 'entries.approve'],
                    auto_post: false,
                },
            ],
        });
        expect(
            await screen.findByText('Versi 1 sedang berlaku', {
                exact: false,
            }),
        ).toBeInTheDocument();
    });

    it('keeps the order of the rules, since the first match wins', async () => {
        const save = `PUT ${base}/approval-policy`;
        const api = mockApi(
            routes(
                policy({
                    version: 2,
                    lock_version: 2,
                    rules: [
                        rule({ transaction_type_key: 'payroll_summary' }),
                        rule({ source: 'integration', stages: [] }),
                    ],
                }),
                { [save]: () => json({ data: policy({ version: 3 }) }) },
            ),
        );

        renderApp(policyPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Naikkan aturan 2' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan kebijakan' }),
        );

        await waitFor(() => expect(api.called(save)).toHaveLength(1));
        expect(
            (api.called(save)[0]?.body as { rules: unknown[] }).rules,
        ).toEqual([
            {
                transaction_type_key: null,
                source: 'integration',
                threshold: null,
                stages: [],
                auto_post: false,
            },
            {
                transaction_type_key: 'payroll_summary',
                source: null,
                threshold: null,
                stages: ['entries.approve'],
                auto_post: false,
            },
        ]);
    });

    it('lets a rule approve by itself or post by itself', async () => {
        const save = `PUT ${base}/approval-policy`;
        const api = mockApi(
            routes(policy(), {
                [save]: () => json({ data: policy({ version: 1 }) }),
            }),
        );

        renderApp(policyPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah aturan' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Hapus tahap 1 aturan 1' }),
        );

        expect(
            screen.getByText('Tanpa tahap: entri disetujui otomatis.'),
        ).toBeInTheDocument();

        await typist.click(
            screen.getByRole('checkbox', { name: /Posting otomatis/ }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan kebijakan' }),
        );

        await waitFor(() => expect(api.called(save)).toHaveLength(1));
        expect(
            (api.called(save)[0]?.body as { rules: unknown[] }).rules,
        ).toEqual([
            {
                transaction_type_key: null,
                source: null,
                threshold: null,
                stages: [],
                auto_post: true,
            },
        ]);
    });

    it('shows a refusal under the rule it belongs to', async () => {
        mockApi(
            routes(policy({ rules: [rule()] }), {
                [`PUT ${base}/approval-policy`]: () =>
                    json(
                        {
                            message: 'Data tidak valid.',
                            errors: {
                                'rules.0.stages.0': [
                                    'Permission tidak dikenal.',
                                ],
                            },
                        },
                        422,
                    ),
            }),
        );

        renderApp(policyPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Simpan kebijakan' }),
        );

        const alert = await screen.findByRole('alert');

        expect(
            within(alert).getByText('Permission tidak dikenal.'),
        ).toBeInTheDocument();
    });

    it('loads the newer policy when it was changed elsewhere', async () => {
        let current = policy({ version: 1, lock_version: 1 });

        mockApi(
            routes(policy(), {
                [`GET ${base}/approval-policy`]: () => json({ data: current }),
                [`PUT ${base}/approval-policy`]: () => {
                    current = policy({
                        version: 2,
                        lock_version: 2,
                        separation_of_duties: true,
                    });

                    return json(
                        {
                            message: 'Berubah di tempat lain.',
                            code: 'VERSION_CONFLICT',
                        },
                        409,
                    );
                },
            }),
        );

        renderApp(policyPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Simpan kebijakan' }),
        );

        expect(
            await screen.findByText(/Kebijakan sudah diubah di tempat lain/),
        ).toBeInTheDocument();
        expect(
            await screen.findByRole('checkbox', {
                name: /Pembuat entri tidak boleh menyetujui/,
            }),
        ).toBeChecked();
    });

    it('is only for those who manage policies', async () => {
        mockApi({
            ...routes(),
            ...withPermissions(['entries.read']),
        });

        renderApp(policyPath);

        await waitFor(() =>
            expect(
                screen.queryByRole('heading', {
                    name: 'Kebijakan persetujuan',
                }),
            ).not.toBeInTheDocument(),
        );
    });
});
