import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from '@/test/harness';

const base = `/api/v1/companies/${workspace.company_id}`;
const defId = '01JREPORTDEF0000000000001A';
const snapId = '01JSNAPSHOT00000000000001';
const newSnapId = '01JSNAPSHOT00000000000002';
const reportPath = `/w/${workspace.company_id}/reports/${defId}`;
const listPath = `${reportPath}/snapshots`;

const definition = (version = 3) => ({
    id: defId,
    key: 'income-statement',
    name: 'Laba Rugi',
    kind: 'income_statement',
    current_version: version,
    lock_version: 1,
    version,
    structure: { basis: 'movement', rows: [], columns: [] },
});

const summary = (overrides: Record<string, unknown> = {}) => ({
    id: snapId,
    definition_id: defId,
    definition_version: 3,
    sequence: 1,
    parameters: { from: '2026-01-01', to: '2026-09-30' },
    mapping_versions: { cash_equivalents: 2 },
    data_boundary: { computed_at: '2026-10-01T03:00:00+00:00' },
    reason: 'Tutup kuartal',
    recomputed_from_id: null,
    published_by: 1,
    published_at: '2026-10-01T03:00:00+00:00',
    ...overrides,
});

const result = {
    definition: { id: defId, key: 'income-statement', version: 3 },
    parameters: { from: '2026-01-01', to: '2026-09-30' },
    functional_currency: 'IDR',
    restricted: false,
    columns: [
        {
            key: 'current',
            label: 'Tahun ini',
            kind: 'period',
            from: '2026-01-01',
            to: '2026-09-30',
            available: true,
            coverage_status: 'complete',
        },
    ],
    rows: [
        {
            key: 'sales',
            label: 'Penjualan',
            type: 'accounts',
            values: { current: '1500000.50' },
        },
    ],
    unmapped_accounts: [],
    coverage_from: '2026-01-01',
    coverage_to: '2026-09-30',
    coverage_granularity: 'transactions',
    coverage_status: 'complete',
};

type Routes = Parameters<typeof mockApi>[0];

const page = (data: unknown[]) => ({
    data,
    links: {},
    meta: { current_page: 1, last_page: 1, total: data.length },
});

const routes = (
    permissions = ['reports.read', 'reports.publish'],
    version = 3,
    extra: Routes = {},
): Routes => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/report-definitions/${defId}`]: () =>
        json({ data: definition(version) }),
    [`GET ${base}/report-definitions/${defId}/result`]: () =>
        json({ data: result }),
    [`GET ${base}/report-snapshots`]: () => json(page([summary()])),
    [`GET ${base}/report-snapshots/${snapId}`]: () =>
        json({ data: { ...summary(), result } }),
    ...extra,
});

describe('report snapshots', () => {
    afterEach(() => vi.restoreAllMocks());

    it('lists the snapshots and marks one made from an older definition version (X-AC-04)', async () => {
        mockApi(routes(undefined, 4));

        renderApp(listPath);

        expect(
            await screen.findByRole('link', { name: 'Snapshot 1' }),
        ).toHaveAttribute('href', `${listPath}/${snapId}`);
        expect(screen.getByText('Tutup kuartal')).toBeInTheDocument();
        expect(await screen.findByText('Perlu dihitung ulang')).toBeVisible();
    });

    it('does not mark a snapshot on the current definition version', async () => {
        mockApi(routes());

        renderApp(listPath);

        await screen.findByRole('link', { name: 'Snapshot 1' });
        expect(
            screen.queryByText('Perlu dihitung ulang'),
        ).not.toBeInTheDocument();
    });

    it('publishes the report with the filter on screen and a reason (RP-FR-14)', async () => {
        const user = userEvent.setup();
        const api = mockApi(
            routes(undefined, 3, {
                [`POST ${base}/report-definitions/${defId}/snapshots`]: () =>
                    json(
                        { data: summary({ id: newSnapId, sequence: 2 }) },
                        201,
                    ),
                [`GET ${base}/report-snapshots/${newSnapId}`]: () =>
                    json({
                        data: {
                            ...summary({ id: newSnapId, sequence: 2 }),
                            result,
                        },
                    }),
            }),
        );

        renderApp(reportPath);

        await user.click(
            await screen.findByRole('button', { name: 'Terbitkan snapshot' }),
        );
        await user.type(
            await screen.findByLabelText('Alasan (opsional)'),
            'Tutup kuartal',
        );
        await user.click(screen.getByRole('button', { name: 'Terbitkan' }));

        await waitFor(() =>
            expect(
                api.called(
                    `POST ${base}/report-definitions/${defId}/snapshots`,
                ),
            ).toHaveLength(1),
        );

        const body = api.called(
            `POST ${base}/report-definitions/${defId}/snapshots`,
        )[0]?.body as Record<string, unknown>;

        expect(body.reason).toBe('Tutup kuartal');
        expect(body.from).toMatch(/^\d{4}-01-01$/);
        expect(body.to).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(
            await screen.findByRole('heading', { name: /snapshot 2/ }),
        ).toBeInTheDocument();
    });

    it('hides publishing from someone without reports.publish', async () => {
        mockApi(routes(['reports.read']));

        renderApp(reportPath);

        await screen.findByRole('heading', { name: 'Laba Rugi' });
        expect(
            screen.queryByRole('button', { name: 'Terbitkan snapshot' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Snapshot' }),
        ).toBeInTheDocument();
    });

    it('shows a snapshot as it was published and recalculates it into a new one (X-FR-03)', async () => {
        const user = userEvent.setup();
        const api = mockApi(
            routes(undefined, 4, {
                [`POST ${base}/report-snapshots/${snapId}/recompute`]: () =>
                    json(
                        {
                            data: summary({
                                id: newSnapId,
                                sequence: 2,
                                definition_version: 4,
                                recomputed_from_id: snapId,
                            }),
                        },
                        201,
                    ),
                [`GET ${base}/report-snapshots/${newSnapId}`]: () =>
                    json({
                        data: {
                            ...summary({
                                id: newSnapId,
                                sequence: 2,
                                definition_version: 4,
                                recomputed_from_id: snapId,
                            }),
                            result,
                        },
                    }),
            }),
        );

        renderApp(`${listPath}/${snapId}`);

        const row = (await screen.findByText('Penjualan')).closest(
            'tr',
        ) as HTMLElement;

        expect(within(row).getByText('1.500.000,50')).toBeInTheDocument();
        expect(
            await screen.findByText('Definisi sudah berubah'),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Hitung ulang' }));
        await user.type(
            await screen.findByLabelText('Alasan (opsional)'),
            'Koreksi',
        );
        await user.click(
            within(await screen.findByRole('dialog')).getByRole('button', {
                name: 'Hitung ulang',
            }),
        );

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/report-snapshots/${snapId}/recompute`),
            ).toHaveLength(1),
        );
        expect(
            api.called(`POST ${base}/report-snapshots/${snapId}/recompute`)[0]
                ?.body,
        ).toEqual({ reason: 'Koreksi' });
        expect(
            await screen.findByRole('link', { name: 'Buka snapshot asal' }),
        ).toHaveAttribute('href', `${listPath}/${snapId}`);
    });

    it('explains why a user limited by data scope cannot open a snapshot (X-AC-01)', async () => {
        mockApi(
            routes(undefined, 3, {
                [`GET ${base}/report-snapshots/${snapId}`]: () =>
                    json({ message: 'Forbidden', code: 'FORBIDDEN' }, 403),
            }),
        );

        renderApp(`${listPath}/${snapId}`);

        expect(
            await screen.findByText('Isi snapshot tidak dapat dibuka'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Penjualan')).not.toBeInTheDocument();
    });
});
