import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

// The statements of a run (CO-FR-10): balance sheet, income statement, indirect cash flow, eliminations and
// adjustments, and the reconciliation of each entity to the group total, with the label of the run.

const groupId = '01JGROUP00000000000000001';
const runId = '01JRUN00000000000000000001';
const base = `/api/v1/groups/${groupId}`;
const parent = workspace.company_id;
const child = '01JCOMPANY00000000000000002';

const line = (id: string, code: string, name: string, amount: string) => ({
    group_account_id: id,
    code,
    name,
    amount,
});
const section = (lines: ReturnType<typeof line>[], total: string) => ({
    lines,
    total,
});

const statements = (overrides: Record<string, unknown> = {}) => ({
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
    created_at: null,
    balance_sheet: {
        assets: section([line('a1', '1000', 'Kas', '1500.00')], '1500.00'),
        liabilities: section(
            [line('l1', '2000', 'Utang usaha', '400.00')],
            '400.00',
        ),
        equity: section([line('e1', '3000', 'Modal', '1000.00')], '1000.00'),
        current_year_earnings: '100.00',
        total_liabilities_and_equity: '1500.00',
        difference: '0.00',
        balanced: true,
    },
    income_statement: {
        income: section([line('i1', '4000', 'Penjualan', '900.00')], '900.00'),
        expenses: section(
            [line('x1', '6000', 'Beban gaji', '800.00')],
            '800.00',
        ),
        net_result: '100.00',
    },
    eliminations: {
        pairs: [
            {
                id: 'p1',
                kind: 'balance',
                company_a_id: parent,
                company_b_id: child,
                amount_a: '300.00',
                amount_b: '300.00',
                difference: '0.00',
                status: 'matched',
                reason: null,
                positions: [],
            },
        ],
        eliminations: [],
        adjustments: [],
        total_eliminated: { debit: '300.00', credit: '300.00' },
        total_adjusted: { debit: '0.00', credit: '0.00' },
    },
    reconciliation: {
        entities: [
            {
                company_id: parent,
                name: 'PT Induk',
                functional_currency: 'IDR',
                book_status: 'closed',
                coverage_status: 'complete',
                accounts: '1000.00',
                translation_difference: '0.00',
                eliminations: '-100.00',
                contribution: '900.00',
            },
            {
                company_id: child,
                name: 'PT Anak',
                functional_currency: 'IDR',
                book_status: 'closed',
                coverage_status: 'complete',
                accounts: '600.00',
                translation_difference: '0.00',
                eliminations: '0.00',
                contribution: '600.00',
            },
        ],
        elimination_differences: '0.00',
        adjustments: '0.00',
        total: '1500.00',
        run_total: '1500.00',
    },
    cash_flow: {
        method: 'indirect',
        net_result: '100.00',
        operating: section(
            [line('o1', '2000', 'Utang usaha', '400.00')],
            '400.00',
        ),
        investing: section([], '0.00'),
        financing: section([line('f1', '3000', 'Modal', '1000.00')], '1000.00'),
        translation_effect: '0.00',
        net_change: '1500.00',
        opening_cash: '0.00',
        closing_cash: '1500.00',
        difference: '0.00',
        unclassified: [],
        reconciled: true,
    },
    ...overrides,
});

const routes = (data: unknown, extra: Record<string, never> = {}) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions: [] }] }),
    'GET /api/v1/groups': () =>
        json({
            data: [
                {
                    id: groupId,
                    name: 'Grup Acme',
                    parent_company_id: parent,
                    presentation_currency: 'IDR',
                    package_policy: 'draft',
                    elimination_tolerance: '0.00',
                    lock_version: 1,
                    abilities: ['read'],
                },
            ],
        }),
    [`GET ${base}/runs/${runId}/statements`]: () => json({ data }),
    ...extra,
});

describe('group statements (CO-FR-10)', () => {
    it('shows the statements of a formal run without a warning', async () => {
        mockApi(routes(statements()));

        renderApp(`/groups/${groupId}/runs/${runId}/statements`);

        const sheet = (
            await screen.findByRole('heading', { name: 'Neraca' })
        ).closest('section')!;

        expect(within(sheet).getByText('Kas')).toBeTruthy();
        expect(within(sheet).getByText('Utang usaha')).toBeTruthy();
        expect(within(sheet).getByText('Laba tahun berjalan')).toBeTruthy();
        expect(screen.getByText('Konsolidasian')).toBeTruthy();
        expect(
            screen.queryByText('Bukan laporan konsolidasian formal'),
        ).toBeNull();

        const income = screen
            .getByRole('heading', { name: 'Laba rugi' })
            .closest('section')!;

        expect(within(income).getByText('Penjualan')).toBeTruthy();
        expect(within(income).getByText('Laba (rugi) bersih')).toBeTruthy();
    });

    it('warns when the run is only a roll-up, or a consolidated draft', async () => {
        mockApi(
            routes(statements({ label: 'management_rollup', formal: false })),
        );

        renderApp(`/groups/${groupId}/runs/${runId}/statements`);

        expect(
            await screen.findByText('Bukan laporan konsolidasian formal'),
        ).toBeTruthy();
        expect(screen.getByText(/hanya roll-up manajemen/)).toBeTruthy();
    });

    it('warns that a consolidated run is still a draft', async () => {
        mockApi(routes(statements({ formal: false, status: 'draft' })));

        renderApp(`/groups/${groupId}/runs/${runId}/statements`);

        expect(await screen.findByText(/masih draf/)).toBeTruthy();
    });

    it('lists accounts without a cash flow class and says the cash flow does not agree', async () => {
        const base_ = statements();

        mockApi(
            routes({
                ...base_,
                cash_flow: {
                    ...base_.cash_flow,
                    reconciled: false,
                    difference: '50.00',
                    unclassified: [line('u1', '1300', 'Piutang', '50.00')],
                },
            }),
        );

        renderApp(`/groups/${groupId}/runs/${runId}/statements`);

        expect(await screen.findByText('Tidak cocok dengan kas')).toBeTruthy();
        expect(screen.getByText('Akun tanpa kelas arus kas')).toBeTruthy();
        expect(screen.getByText(/1300 · Piutang/)).toBeTruthy();
    });

    it('walks each entity to the group total', async () => {
        mockApi(routes(statements()));

        renderApp(`/groups/${groupId}/runs/${runId}/statements`);

        const section_ = (
            await screen.findByRole('heading', {
                name: 'Rekonsiliasi per entitas',
            })
        ).closest('section')!;

        expect(within(section_).getByText('PT Induk')).toBeTruthy();
        expect(within(section_).getByText('PT Anak')).toBeTruthy();
        expect(within(section_).getByText('Total run')).toBeTruthy();
    });

    it('exports the statements as CSV', async () => {
        let downloaded: string | undefined;

        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                downloaded = this.download;
            },
        );

        const api = mockApi({
            ...routes(statements()),
            [`GET ${base}/runs/${runId}/statements`]: (call) =>
                call.accept?.includes('text/csv')
                    ? new Response('section,code\n', {
                          headers: {
                              'Content-Type': 'text/csv',
                              'Content-Disposition':
                                  'attachment; filename="statements.csv"',
                          },
                      })
                    : json({ data: statements() }),
        });

        renderApp(`/groups/${groupId}/runs/${runId}/statements`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Ekspor CSV' }),
        );

        await waitFor(() => expect(downloaded).toBe('statements.csv'));
        expect(
            api
                .called(`GET ${base}/runs/${runId}/statements`)
                .some((call) => call.accept?.includes('text/csv')),
        ).toBe(true);
    });
});
