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

const yearId = '01JYEAR000000000000000001';
const planId = '01JPLAN000000000000000001';
const draftId = '01JVERSION0000000000000001';
const publishedId = '01JVERSION0000000000000002';
const rentId = '01JACCOUNT0000000000000001';
const salesId = '01JACCOUNT0000000000000002';
const branchTypeId = '01JDIMTYPE0000000000000001';
const jakartaId = '01JDIMVALUE000000000000001';
const periodId = (n: number) =>
    `01JPERIOD00000000000000${String(n).padStart(3, '0')}`;

const plan = {
    id: planId,
    name: 'Anggaran 2026',
    objective: null,
    starts_on: '2026-01-01',
    ends_on: '2026-12-31',
    scope_dimension_value_id: null,
    description: 'Rencana tahunan',
    lock_version: 1,
};

const version = (overrides: Record<string, unknown>) => ({
    id: draftId,
    plan_id: planId,
    name: 'Draf awal',
    status: 'draft',
    origin_version_id: null,
    published_by: null,
    published_at: null,
    lines_count: 1,
    lock_version: 3,
    ...overrides,
});

const draft = version({});
const published = version({
    id: publishedId,
    name: 'Anggaran awal',
    status: 'published',
    lock_version: 4,
});

const account_ = (id: string, code: string, name: string, cls: string) => ({
    id,
    code,
    name,
    class: cls,
    normal_balance: cls === 'income' ? 'credit' : 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    allowed_sources: ['entry'],
    dimension_requirements: {},
    currency_restriction: 'any',
    restricted_currency: null,
    is_revaluable: false,
    lock_version: 1,
});

const accounts = [
    account_(rentId, '6100', 'Beban Sewa', 'expense'),
    account_(salesId, '4100', 'Penjualan', 'income'),
];

const line = (period: number, amount: string) => ({
    id: `01JLINE0000000000000000${String(period).padStart(3, '0')}`,
    plan_version_id: draftId,
    period_id: periodId(period),
    account_id: rentId,
    dimension_set_id: '01JSET00000000000000000001',
    dimensions: {},
    amount,
    note: null,
});

const periods = [1, 2].map((n) => ({
    id: periodId(n),
    fiscal_year_id: yearId,
    number: n,
    name: `2026-0${n}`,
    start_date: `2026-0${n}-01`,
    end_date: `2026-0${n}-28`,
    status: 'open',
}));

const routes = (
    permissions: string[],
    lines: unknown[] = [line(1, '1500000.000000')],
) => ({
    ...withPermissions(permissions),
    [`GET ${base}/plans`]: () => list([plan]),
    [`GET ${base}/plans/${planId}`]: () => json({ data: plan }),
    [`GET ${base}/plan-versions`]: () => list([draft, published]),
    [`GET ${base}/plan-versions/${draftId}`]: () => json({ data: draft }),
    [`GET ${base}/plan-versions/${publishedId}`]: () =>
        json({ data: published }),
    [`GET ${base}/plan-versions/${draftId}/lines`]: () => list(lines),
    [`GET ${base}/plan-versions/${publishedId}/lines`]: () => list(lines),
    [`GET ${base}/planning-policy`]: () =>
        json({
            data: {
                require_distinct_publisher: false,
                default_versions: [
                    { fiscal_year_id: yearId, plan_version_id: publishedId },
                ],
                lock_version: 2,
            },
        }),
    [`GET ${base}/fiscal-years`]: () =>
        json({
            data: [
                {
                    id: yearId,
                    name: 'FY2026',
                    start_date: '2026-01-01',
                    end_date: '2026-12-31',
                    period_count: 12,
                },
            ],
        }),
    [`GET ${base}/periods`]: () => list(periods),
    [`GET ${base}/accounts`]: () => list(accounts),
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
});

describe('plans and versions (PL-FR-01, PL-FR-04)', () => {
    it('lists plans with their versions, marking the default comparison', async () => {
        mockApi(routes(['plans.read']));

        renderApp(`${home}/plans`);

        expect(await screen.findByText('Anggaran 2026')).toBeInTheDocument();
        expect(screen.getByText('Draf awal')).toBeInTheDocument();
        expect(screen.getByText('Anggaran awal')).toBeInTheDocument();
        expect(screen.getByText('Pembanding default')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Rencana baru' }),
        ).toBeNull();
    });

    it('revises a published version into a new draft and opens it (PL-AC-01)', async () => {
        const revise = `POST ${base}/plan-versions/${publishedId}/revise`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [revise]: () =>
                json(
                    {
                        data: version({
                            id: '01JVERSION0000000000000003',
                            name: 'Revisi 1',
                            origin_version_id: publishedId,
                        }),
                    },
                    201,
                ),
            [`GET ${base}/plan-versions/01JVERSION0000000000000003`]: () =>
                json({
                    data: version({
                        id: '01JVERSION0000000000000003',
                        name: 'Revisi 1',
                    }),
                }),
            [`GET ${base}/plan-versions/01JVERSION0000000000000003/lines`]:
                () => list([]),
        });

        renderApp(`${home}/plans`);
        const typist = userEvent.setup();

        await rowAction(typist, 'Anggaran awal', 'Ubah versi terbit');
        await typist.type(
            await screen.findByLabelText('Nama versi'),
            'Revisi 1',
        );
        await typist.click(screen.getByRole('button', { name: 'Buat draf' }));

        await waitFor(() => expect(api.called(revise)).toHaveLength(1));
        expect(api.called(revise)[0]?.body).toEqual({ name: 'Revisi 1' });
        expect(
            await screen.findByRole('heading', {
                name: /Anggaran 2026 · Revisi 1/,
            }),
        ).toBeInTheDocument();
    });

    it('publishes a draft after confirming, with its lock version', async () => {
        const publish = `POST ${base}/plan-versions/${draftId}/publish`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.publish']),
            [publish]: () =>
                json({
                    data: { ...draft, status: 'published', lock_version: 4 },
                }),
        });

        renderApp(`${home}/plans`);
        const typist = userEvent.setup();

        await rowAction(typist, 'Draf awal', 'Terbitkan');
        await confirmWith(typist, 'Terbitkan');

        await waitFor(() => expect(api.called(publish)).toHaveLength(1));
        expect(api.called(publish)[0]?.body).toEqual({ lock_version: 3 });
    });

    it("shows the server's reason when publishing is refused (PL-AC-03)", async () => {
        const publish = `POST ${base}/plan-versions/${draftId}/publish`;

        mockApi({
            ...routes(['plans.read', 'plans.publish']),
            [publish]: () =>
                json(
                    {
                        message:
                            'Penerbit harus berbeda dari pengubah terakhir.',
                        code: 'SEGREGATION_OF_DUTIES',
                    },
                    403,
                ),
        });

        renderApp(`${home}/plans`);
        const typist = userEvent.setup();

        await rowAction(typist, 'Draf awal', 'Terbitkan');
        await confirmWith(typist, 'Terbitkan');

        expect(
            await screen.findByText(
                'Penerbit harus berbeda dari pengubah terakhir.',
            ),
        ).toBeInTheDocument();
    });

    it('saves the policy with the changed default only', async () => {
        const update = `PUT ${base}/planning-policy`;
        const api = mockApi({
            ...routes(['plans.read', 'planning-policy.manage']),
            [update]: () =>
                json({
                    data: {
                        require_distinct_publisher: true,
                        default_versions: [
                            {
                                fiscal_year_id: yearId,
                                plan_version_id: publishedId,
                            },
                        ],
                        lock_version: 3,
                    },
                }),
        });

        renderApp(`${home}/plans`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Kebijakan' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            await within(dialog).findByRole('checkbox', {
                name: /Penerbit harus berbeda/,
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 2,
            require_distinct_publisher: true,
            default_versions: [],
        });
    });
});

describe('plan grid (PL-FR-02, PL-FR-03)', () => {
    it('shows a draft as a grid with totals and edits only what changed', async () => {
        const enter = `PUT ${base}/plan-versions/${draftId}/lines`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [enter]: () => json({ data: { created: 1, updated: 0 } }),
        });

        renderApp(`${home}/plan-versions/${draftId}`);
        const typist = userEvent.setup();

        const cell = await screen.findByLabelText('6100 · Beban Sewa, 2026-01');

        expect(cell).toHaveValue('1.500.000');
        expect(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        ).toBeDisabled();

        await typist.type(
            screen.getByLabelText('6100 · Beban Sewa, 2026-02'),
            '250000',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() => expect(api.called(enter)).toHaveLength(1));
        expect(api.called(enter)[0]?.body).toEqual({
            lines: [
                {
                    period_id: periodId(2),
                    account_id: rentId,
                    amount: '250000',
                },
            ],
        });
    });

    it('deletes the line of a cell that was emptied', async () => {
        const remove = `DELETE ${base}/plan-versions/${draftId}/lines/${line(1, '0').id}`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [remove]: () => new Response(null, { status: 204 }),
        });

        renderApp(`${home}/plan-versions/${draftId}`);
        const typist = userEvent.setup();

        await typist.clear(
            await screen.findByLabelText('6100 · Beban Sewa, 2026-01'),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() => expect(api.called(remove)).toHaveLength(1));
        expect(
            api.called(`PUT ${base}/plan-versions/${draftId}/lines`),
        ).toHaveLength(0);
    });

    it('adds a row for an account and dimension value', async () => {
        const enter = `PUT ${base}/plan-versions/${draftId}/lines`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [enter]: () => json({ data: { created: 1, updated: 0 } }),
        });

        renderApp(`${home}/plan-versions/${draftId}`);
        const typist = userEvent.setup();
        const form = await screen.findByRole('form', { name: 'Tambah baris' });

        await typist.click(
            within(form).getByRole('combobox', { name: 'Akun' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '4100 · Penjualan' }),
        );
        await typist.click(
            within(form).getByRole('combobox', { name: 'Cabang' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'JKT · Jakarta' }),
        );
        await typist.click(
            within(form).getByRole('button', { name: 'Tambah' }),
        );

        await typist.type(
            await screen.findByLabelText('4100 · Penjualan, 2026-01'),
            '9000000',
        );
        expect(screen.getByText('JKT · Jakarta')).toBeInTheDocument();
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() => expect(api.called(enter)).toHaveLength(1));
        expect(api.called(enter)[0]?.body).toEqual({
            lines: [
                {
                    period_id: periodId(1),
                    account_id: salesId,
                    dimensions: { branch: jakartaId },
                    amount: '9000000',
                },
            ],
        });
    });

    it('names the cell that the server refused', async () => {
        const enter = `PUT ${base}/plan-versions/${draftId}/lines`;

        mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [enter]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'lines.0.account_id': [
                                'Akun tidak dapat direncanakan.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(`${home}/plan-versions/${draftId}`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('6100 · Beban Sewa, 2026-02'),
            '1',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        expect(
            await screen.findByText(
                '6100 · Beban Sewa, 2026-02: Akun tidak dapat direncanakan.',
            ),
        ).toBeInTheDocument();
    });

    it('shows a published version without inputs or save', async () => {
        mockApi(routes(['plans.read', 'plans.manage', 'plans.publish']));

        renderApp(`${home}/plan-versions/${publishedId}`);

        expect(await screen.findByText(/Beban Sewa/)).toBeInTheDocument();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Simpan perubahan' }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Ubah versi terbit' }),
        ).toBeInTheDocument();
    });

    it('lists every problem of a rejected CSV import (PL-AC-02)', async () => {
        const importPath = `POST ${base}/plan-versions/${draftId}/lines/import`;

        mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [importPath]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'rows.2.account_code': [
                                'Akun induk tidak dapat direncanakan.',
                            ],
                            'rows.5.period': ['Periode di luar tahun buku.'],
                        },
                    },
                    422,
                ),
        });

        renderApp(`${home}/plan-versions/${draftId}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Impor CSV' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.upload(
            within(dialog).getByLabelText('File CSV'),
            new File(['period,account_code,amount\n'], 'plan.csv', {
                type: 'text/csv',
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor' }),
        );

        expect(
            await within(dialog).findByText(
                /Akun induk tidak dapat direncanakan/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/Periode di luar tahun buku/),
        ).toBeInTheDocument();
    });

    it('copies lines from the actuals of a period into another', async () => {
        const copy = `POST ${base}/plan-versions/${draftId}/lines/copy`;
        const api = mockApi({
            ...routes(['plans.read', 'plans.manage']),
            [copy]: () =>
                json({ data: { created: 3, updated: 0, skipped: 1 } }),
        });

        renderApp(`${home}/plan-versions/${draftId}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Salin' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('radio', {
                name: 'Dari realisasi satu periode',
            }),
        );
        await typist.click(
            within(dialog).getByRole('combobox', { name: 'Realisasi periode' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '2026-01' }),
        );
        await typist.click(
            within(dialog).getByRole('combobox', {
                name: 'Disalin ke periode',
            }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '2026-02' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Salin' }),
        );

        await waitFor(() => expect(api.called(copy)).toHaveLength(1));
        expect(api.called(copy)[0]?.body).toEqual({
            actuals_period_id: periodId(1),
            to_period_id: periodId(2),
        });
    });
});
