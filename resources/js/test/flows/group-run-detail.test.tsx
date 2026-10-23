import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

// The detail of a formal run (CO-FR-05, CO-FR-06, CO-FR-07): its label, the rates it translated at, and the
// intragroup pairs it eliminated or left as exceptions.

const groupId = '01JGROUP00000000000000001';
const runId = '01JRUN00000000000000000001';
const parent = workspace.company_id;
const child = '01JCOMPANY00000000000000002';
const base = `/api/v1/groups/${groupId}`;

const amounts = (overrides: Record<string, string> = {}) => ({
    opening_debit: '0.00',
    opening_credit: '0.00',
    debit: '0.00',
    credit: '0.00',
    closing: '0.00',
    closing_debit: '0.00',
    closing_credit: '0.00',
    ...overrides,
});

const entity = (companyId: string, name: string, translation: unknown) => ({
    company_id: companyId,
    name,
    functional_currency: translation ? 'USD' : 'IDR',
    book_status: 'closed',
    coverage_from: '2026-01-01',
    coverage_to: '2026-01-31',
    coverage_granularity: 'transactions',
    coverage_status: 'complete',
    balanced: true,
    source: {},
    translation,
});

const formalRun = (overrides: Record<string, unknown> = {}) => ({
    id: runId,
    group_id: groupId,
    from: '2026-01-01',
    to: '2026-01-31',
    cutoff_date: '2026-01-31',
    label: 'consolidated',
    formal: true,
    status: 'complete',
    presentation_currency: 'IDR',
    mapping_version: 2,
    translation_policy_version: 3,
    package_policy: 'draft',
    elimination_tolerance: '10.00',
    coverage_status: 'complete',
    created_by: 1,
    created_at: '2026-02-01T01:00:00+00:00',
    perimeter: [],
    entities: [
        entity(parent, 'PT Induk', null),
        entity(child, 'PT Anak', {
            policy_version: 3,
            functional_currency: 'USD',
            presentation_currency: 'IDR',
            rates: [
                {
                    purpose: 'closing',
                    rate_type: 'Kurs penutup',
                    requested_date: '2026-01-31',
                    effective_date: '2026-01-30',
                    rate: '15800.5',
                    version: 2,
                },
            ],
            difference: {
                account_id: 'x',
                opening: '0.00',
                closing: '1200.00',
            },
        }),
    ],
    lines: [
        {
            group_account_id: '01JGACC00000000000000001',
            code: '1300',
            name: 'Piutang',
            class: 'asset',
            cash_flow_class: 'operating',
            ...amounts({ closing_debit: '500.00', closing: '500.00' }),
            sources: [],
            eliminations: [
                {
                    match_id: 'm1',
                    kind: 'elimination',
                    company_id: parent,
                    counterparty_company_id: child,
                    account_id: 'a',
                    code: '1300',
                    name: 'Piutang',
                    class: 'asset',
                    ...amounts({
                        closing_credit: '300.00',
                        closing: '-300.00',
                    }),
                },
            ],
            adjustments: [],
        },
    ],
    exceptions: [],
    intercompany: [
        {
            id: 'm1',
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
        {
            id: 'm2',
            kind: 'flow',
            company_a_id: parent,
            company_b_id: child,
            amount_a: '900.00',
            amount_b: '100.00',
            difference: '800.00',
            status: 'exception',
            reason: 'difference',
            positions: [],
        },
    ],
    unassigned_eliminations: [],
    adjustments: [],
    totals: {
        eliminations: amounts(),
        adjustments: amounts(),
        mapped: amounts(),
        unmapped: amounts(),
        all: amounts({ closing_debit: '200.00', closing_credit: '200.00' }),
    },
    balanced: true,
    ...overrides,
});

const routes = (run: unknown) => ({
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
                    elimination_tolerance: '10.00',
                    lock_version: 1,
                    abilities: ['read'],
                },
            ],
        }),
    [`GET ${base}/runs/${runId}`]: () => json({ data: run }),
});

describe('formal run detail', () => {
    it('labels a complete formal run as consolidated, without the roll-up warning', async () => {
        mockApi(routes(formalRun()));

        renderApp(`/groups/${groupId}/runs/${runId}`);

        expect(await screen.findByText('Konsolidasian')).toBeTruthy();
        expect(
            screen.queryByText('Bukan laporan konsolidasian formal'),
        ).toBeNull();
        expect(screen.getByText(/kebijakan translasi versi 3/)).toBeTruthy();
    });

    it('keeps the draft warning for a formal run whose packages are not all closed', async () => {
        mockApi(routes(formalRun({ formal: false, status: 'draft' })));

        renderApp(`/groups/${groupId}/runs/${runId}`);

        expect(
            await screen.findByText('Draf', {
                selector: '[data-slot="alert-title"]',
            }),
        ).toBeTruthy();
    });

    it('shows the rates translated at, with the requested and the effective date', async () => {
        mockApi(routes(formalRun()));

        renderApp(`/groups/${groupId}/runs/${runId}`);

        const section = (
            await screen.findByRole('heading', { name: 'Translasi' })
        ).closest('section')!;

        expect(within(section).getByText('Aset dan liabilitas')).toBeTruthy();
        expect(within(section).getByText('Kurs penutup')).toBeTruthy();
        expect(within(section).getByText('15.800,5')).toBeTruthy();
        expect(
            within(section).getByText(/Selisih translasi: awal/),
        ).toBeTruthy();
    });

    it('lists the pairs: eliminated ones, and the exception with its reason', async () => {
        mockApi(routes(formalRun()));

        renderApp(`/groups/${groupId}/runs/${runId}`);

        const section = (
            await screen.findByRole('heading', {
                name: 'Transaksi antaranggota',
            })
        ).closest('section')!;

        expect(within(section).getByText('Dieliminasi')).toBeTruthy();
        expect(
            within(section).getByText('Selisih melebihi toleransi'),
        ).toBeTruthy();
    });

    it('shows the elimination under its group line when the line is opened', async () => {
        mockApi(routes(formalRun()));

        renderApp(`/groups/${groupId}/runs/${runId}`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', {
                name: 'Rincian akun perusahaan untuk Piutang',
            }),
        );

        expect(await screen.findByText(/^Eliminasi · PT Induk/)).toBeTruthy();
    });
});
