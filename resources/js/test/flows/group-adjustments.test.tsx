import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

// Consolidation adjustments on a run (CO-FR-08, CO-AC-04): proposed with a source, a reason and balanced
// lines; approved or rejected by someone other than the proposer.

const groupId = '01JGROUP00000000000000001';
const runId = '01JRUN00000000000000000001';
const base = `/api/v1/groups/${groupId}`;

const amounts = {
    opening_debit: '0.00',
    opening_credit: '0.00',
    debit: '0.00',
    credit: '0.00',
    closing: '0.00',
    closing_debit: '0.00',
    closing_credit: '0.00',
};

const run = {
    id: runId,
    group_id: groupId,
    from: '2026-01-01',
    to: '2026-01-31',
    cutoff_date: '2026-01-31',
    label: 'consolidated',
    formal: true,
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
    entities: [],
    lines: [],
    exceptions: [],
    intercompany: [],
    unassigned_eliminations: [],
    adjustments: [],
    totals: {
        eliminations: amounts,
        adjustments: amounts,
        mapped: amounts,
        unmapped: amounts,
        all: amounts,
    },
    balanced: true,
};

const adjustment = (overrides: Record<string, unknown> = {}) => ({
    id: '01JADJ00000000000000000001',
    run_id: runId,
    source: 'Memo audit 12',
    reason: 'Koreksi penyusutan',
    status: 'pending',
    proposed_by: 2,
    decided_by: null,
    decided_at: null,
    rejection_reason: null,
    created_at: '2026-02-02T01:00:00+00:00',
    lines: [
        {
            group_account_id: '01JGACC00000000000000001',
            code: '6100',
            name: 'Beban penyusutan',
            class: 'expense',
            debit: '500.00',
            credit: '0.00',
        },
        {
            group_account_id: '01JGACC00000000000000002',
            code: '1500',
            name: 'Akumulasi penyusutan',
            class: 'asset',
            debit: '0.00',
            credit: '500.00',
        },
    ],
    ...overrides,
});

const groupAccounts = [
    {
        id: '01JGACC00000000000000001',
        code: '6100',
        name: 'Beban penyusutan',
        class: 'expense',
        cash_flow_class: null,
        is_active: true,
        lock_version: 1,
    },
    {
        id: '01JGACC00000000000000002',
        code: '1500',
        name: 'Akumulasi penyusutan',
        class: 'asset',
        cash_flow_class: null,
        is_active: true,
        lock_version: 1,
    },
];

const routes = (abilities: string[], adjustments: unknown[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions: [] }] }),
    'GET /api/v1/groups': () =>
        json({
            data: [
                {
                    id: groupId,
                    name: 'Grup Acme',
                    parent_company_id: workspace.company_id,
                    presentation_currency: 'IDR',
                    package_policy: 'draft',
                    elimination_tolerance: '0.00',
                    lock_version: 1,
                    abilities,
                },
            ],
        }),
    [`GET ${base}/runs/${runId}`]: () => json({ data: run }),
    [`GET ${base}/runs/${runId}/adjustments`]: () =>
        json({ data: adjustments }),
    [`GET ${base}/accounts`]: () => json({ data: groupAccounts }),
});

describe('adjustments of a run (CO-FR-08)', () => {
    it('lists them with their status and offers nothing to a reader', async () => {
        mockApi(routes(['read'], [adjustment()]));

        renderApp(`/groups/${groupId}/runs/${runId}`);

        expect(await screen.findByText('Memo audit 12')).toBeTruthy();
        expect(screen.getByText('Menunggu persetujuan')).toBeTruthy();
        expect(screen.getByText('Koreksi penyusutan')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Setujui' })).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Usulkan penyesuaian' }),
        ).toBeNull();
    });

    it('proposes only once the lines balance, sending amounts as decimal strings', async () => {
        const api = mockApi({
            ...routes(['read', 'run'], []),
            [`POST ${base}/runs/${runId}/adjustments`]: () =>
                json({ data: adjustment({ proposed_by: 1 }) }, 201),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Usulkan penyesuaian' }),
        );
        const dialog = await screen.findByRole('dialog');
        const submit = within(dialog).getByRole('button', { name: 'Usulkan' });

        await user.type(
            within(dialog).getByLabelText('Sumber'),
            'Memo audit 12',
        );
        await user.type(
            within(dialog).getByLabelText('Alasan'),
            'Koreksi penyusutan',
        );

        await user.click(
            within(dialog).getByRole('combobox', { name: 'Akun grup baris 1' }),
        );
        await user.click(
            await screen.findByRole('option', {
                name: '6100 · Beban penyusutan',
            }),
        );
        await user.type(within(dialog).getByLabelText('Debit baris 1'), '500');
        expect(submit.hasAttribute('disabled')).toBe(true);
        expect(within(dialog).getByRole('status').textContent).toContain(
            'Belum seimbang',
        );

        await user.click(
            within(dialog).getByRole('combobox', { name: 'Akun grup baris 2' }),
        );
        await user.click(
            await screen.findByRole('option', {
                name: '1500 · Akumulasi penyusutan',
            }),
        );
        await user.type(within(dialog).getByLabelText('Kredit baris 2'), '500');
        expect(within(dialog).getByRole('status').textContent).toContain(
            'Seimbang',
        );

        await user.click(submit);

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/runs/${runId}/adjustments`),
            ).toHaveLength(1),
        );
        expect(
            api.called(`POST ${base}/runs/${runId}/adjustments`)[0]?.body,
        ).toEqual({
            source: 'Memo audit 12',
            reason: 'Koreksi penyusutan',
            lines: [
                {
                    group_account_id: groupAccounts[0]!.id,
                    debit: '500',
                    credit: '0',
                },
                {
                    group_account_id: groupAccounts[1]!.id,
                    debit: '0',
                    credit: '500',
                },
            ],
        });
    });

    it('lets an approver decide on another person’s adjustment after confirming', async () => {
        const api = mockApi({
            ...routes(['read', 'run', 'approve'], [adjustment()]),
            [`POST ${base}/adjustments/01JADJ00000000000000000001/approve`]:
                () =>
                    json({
                        data: adjustment({ status: 'approved', decided_by: 1 }),
                    }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Setujui' }),
        );
        const confirm = await screen.findByRole('alertdialog');

        await user.click(
            within(confirm).getByRole('button', { name: 'Setujui' }),
        );

        await waitFor(() =>
            expect(
                api.called(
                    `POST ${base}/adjustments/01JADJ00000000000000000001/approve`,
                ),
            ).toHaveLength(1),
        );
        // The run is read again: an approved adjustment is part of its result.
        await waitFor(() =>
            expect(
                api.called(`GET ${base}/runs/${runId}`).length,
            ).toBeGreaterThan(1),
        );
    });

    it('does not let the proposer decide on their own adjustment', async () => {
        mockApi(
            routes(
                ['read', 'run', 'approve'],
                [adjustment({ proposed_by: 1 })],
            ),
        );

        renderApp(`/groups/${groupId}/runs/${runId}`);

        const approve = await screen.findByRole('button', { name: 'Setujui' });

        expect(approve.hasAttribute('disabled')).toBe(true);
        expect(
            screen
                .getByRole('button', { name: 'Tolak' })
                .hasAttribute('disabled'),
        ).toBe(true);
        expect(screen.getByText(/Anda yang mengusulkannya/)).toBeTruthy();
    });

    it('rejects with a required reason', async () => {
        const api = mockApi({
            ...routes(['read', 'run', 'approve'], [adjustment()]),
            [`POST ${base}/adjustments/01JADJ00000000000000000001/reject`]:
                () =>
                    json({
                        data: adjustment({
                            status: 'rejected',
                            rejection_reason: 'Tanpa bukti',
                        }),
                    }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}`);
        const user = userEvent.setup();

        await user.click(await screen.findByRole('button', { name: 'Tolak' }));
        const dialog = await screen.findByRole('dialog');

        await user.click(within(dialog).getByRole('button', { name: 'Tolak' }));
        expect(await within(dialog).findByText('Wajib diisi')).toBeTruthy();
        expect(
            api.called(
                `POST ${base}/adjustments/01JADJ00000000000000000001/reject`,
            ),
        ).toHaveLength(0);

        await user.type(
            within(dialog).getByLabelText('Alasan penolakan'),
            'Tanpa bukti',
        );
        await user.click(within(dialog).getByRole('button', { name: 'Tolak' }));

        await waitFor(() =>
            expect(
                api.called(
                    `POST ${base}/adjustments/01JADJ00000000000000000001/reject`,
                )[0]?.body,
            ).toEqual({ reason: 'Tanpa bukti' }),
        );
    });
});
