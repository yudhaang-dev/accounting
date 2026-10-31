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
const home = `/w/${workspace.company_id}`;

const planId = '01JPLAN000000000000000001';
const versionId = '01JVERSION0000000000000002';
const salesId = '01JACCOUNT0000000000000002';
const branchTypeId = '01JDIMTYPE0000000000000001';
const jakartaId = '01JDIMVALUE000000000000001';
const periodId = (n: number) =>
    `01JPERIOD00000000000000${String(n).padStart(3, '0')}`;

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

const plan = {
    id: planId,
    name: 'Promosi Jakarta',
    objective: 'Penjualan naik 15%',
    starts_on: '2026-01-01',
    ends_on: '2026-02-28',
    scope_dimension_value_id: jakartaId,
    description: null,
    lock_version: 1,
};

const dimensions = {
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
        list([
            {
                id: jakartaId,
                dimension_type_id: branchTypeId,
                code: 'JKT',
                name: 'Jakarta',
                parent_id: null,
                is_leaf: true,
                valid_from: null,
                valid_to: null,
                status: 'active',
                lock_version: 1,
            },
        ]),
};

const cell = (
    actual: string,
    initial: string | null,
    revision: string | null,
) => ({
    actual,
    initial,
    revision,
    variances: {
        initial:
            initial === null
                ? null
                : {
                      amount: '-200000.000000',
                      percentage: '-10.000000',
                      favorable: false,
                  },
        revision:
            revision === null
                ? null
                : {
                      amount: '100000.000000',
                      percentage: '5.000000',
                      favorable: true,
                  },
    },
});

const evaluation = {
    plan: {
        id: planId,
        name: 'Promosi Jakarta',
        objective: 'Penjualan naik 15%',
        starts_on: '2026-01-01',
        ends_on: '2026-02-28',
        scope_dimension_value_id: jakartaId,
    },
    functional_currency: 'IDR',
    restricted: false,
    versions: {
        initial: { id: versionId, name: 'Estimasi awal' },
        revision: { id: versionId, name: 'Estimasi awal' },
    },
    available_versions: [{ id: versionId, name: 'Estimasi awal' }],
    periods: [1, 2].map((n) => ({
        id: periodId(n),
        name: `2026-0${n}`,
        start_date: `2026-0${n}-01`,
        end_date: `2026-0${n}-28`,
        result: {
            actual: '1800000.000000',
            initial: '2000000.000000',
            revision: '1700000.000000',
        },
        variance: { initial: '-200000.000000', revision: '100000.000000' },
        cumulative_variance: {
            initial: `-${n}00000.000000`,
            revision: `${n}00000.000000`,
        },
    })),
    rows: [
        {
            account_id: salesId,
            code: '4100',
            name: 'Penjualan',
            class: 'income',
            normal_balance: 'credit',
            cells: [1, 2].map((n) => ({
                period_id: periodId(n),
                ...cell('1800000.000000', '2000000.000000', '1700000.000000'),
            })),
            total: cell('3600000.000000', '4000000.000000', '3400000.000000'),
        },
    ],
};

const routes = (permissions: string[]) => ({
    ...withPermissions(permissions),
    ...dimensions,
    [`GET ${base}/plans`]: () => list([plan]),
    [`GET ${base}/plans/${planId}`]: () => json({ data: plan }),
    [`GET ${base}/plan-versions`]: () => list([]),
    [`GET ${base}/planning-policy`]: () =>
        json({
            data: {
                require_distinct_publisher: false,
                default_versions: [],
                lock_version: 1,
            },
        }),
    [`GET ${base}/fiscal-years`]: () => json({ data: [] }),
});

describe('activity plan (PL-FR-01, PL-AC-02)', () => {
    it('creates a plan with its own period and a dimension scope', async () => {
        const create = `POST ${base}/plans`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [create]: () => json({ data: plan }),
        });

        renderApp(`${home}/plans`);
        const typist = userEvent.setup();

        await typist.click(
            (
                await screen.findAllByRole('button', { name: 'Rencana baru' })
            )[0]!,
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Nama rencana'),
            'Promosi Jakarta',
        );
        await typist.type(
            within(dialog).getByLabelText('Tujuan atau hipotesis'),
            'Penjualan naik 15%',
        );
        await typist.type(within(dialog).getByLabelText('Mulai'), '2026-03-15');
        await typist.type(
            within(dialog).getByLabelText('Selesai'),
            '2027-02-10',
        );
        await typist.click(
            within(dialog).getByRole('combobox', {
                name: 'Jenis dimensi ruang lingkup',
            }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'Cabang' }),
        );
        await typist.click(
            within(dialog).getByRole('combobox', {
                name: 'Nilai dimensi ruang lingkup',
            }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'JKT · Jakarta' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'Promosi Jakarta',
            objective: 'Penjualan naik 15%',
            description: null,
            starts_on: '2026-03-15',
            ends_on: '2027-02-10',
            scope_dimension_value_id: jakartaId,
        });
    });

    it('fixes the period and the scope once a version is published', async () => {
        mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [`GET ${base}/plan-versions`]: () =>
                list([
                    {
                        id: versionId,
                        plan_id: planId,
                        name: 'Estimasi awal',
                        status: 'published',
                        origin_version_id: null,
                        published_by: null,
                        published_at: null,
                        lines_count: 1,
                        lock_version: 2,
                    },
                ]),
        });

        renderApp(`${home}/plans`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Ubah rencana Promosi Jakarta',
            }),
        );
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByLabelText('Mulai')).toBeDisabled();
        expect(within(dialog).getByLabelText('Selesai')).toBeDisabled();
    });
});

describe('plan evaluation (RP-FR-11, RP-FR-12)', () => {
    it('shows the initial estimate, the revision and the actual side by side', async () => {
        const url = `GET ${base}/reports/plan-evaluation`;
        const api = mockApi({
            ...routes(['plans.read', 'reports.read']),
            [url]: () => json({ data: evaluation }),
        });

        renderApp(`${home}/plans/${planId}/evaluation`);

        expect(
            await screen.findByRole('heading', {
                name: 'Evaluasi Promosi Jakarta',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Cabang: Jakarta')).toBeInTheDocument();
        expect(screen.getByText('Penjualan naik 15%')).toBeInTheDocument();
        expect(screen.getByText('4100')).toBeInTheDocument();
        expect(screen.getByText('3.600.000,00')).toBeInTheDocument();
        expect(screen.getByText('4.000.000,00')).toBeInTheDocument();
        expect(api.called(url)[0]?.query).toEqual({ plan_id: planId });
    });

    it('says so when no version is published yet', async () => {
        mockApi({
            ...routes(['plans.read', 'reports.read']),
            [`GET ${base}/reports/plan-evaluation`]: () =>
                json({
                    data: {
                        ...evaluation,
                        versions: { initial: null, revision: null },
                        available_versions: [],
                    },
                }),
        });

        renderApp(`${home}/plans/${planId}/evaluation`);

        expect(
            await screen.findByText(/Belum ada versi terbit/),
        ).toBeInTheDocument();
    });
});
