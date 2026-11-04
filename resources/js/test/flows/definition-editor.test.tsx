import { screen, waitFor } from '@testing-library/react';
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
const newPath = `/w/${workspace.company_id}/reports/new`;
const defId = '01JREPORTDEF0000000000001A';
const editPath = `/w/${workspace.company_id}/reports/${defId}/edit`;
const listPath = `/w/${workspace.company_id}/reports`;

const page = (data: unknown[]) => ({
    data,
    links: {},
    meta: { current_page: 1, last_page: 1, total: data.length },
});

const definition = {
    id: defId,
    key: 'income',
    name: 'Laba Rugi',
    kind: 'income_statement',
    current_version: 3,
    lock_version: 4,
    publish_on_period_close: true,
    publish_on_year_close: false,
    version: 3,
    structure: {
        basis: 'movement',
        rows: [
            {
                key: 'sales',
                label: 'Penjualan',
                type: 'accounts',
                sign: 'credit',
                basis: 'movement',
                accounts: [{ type: 'class', class: 'income' }],
            },
            {
                key: 'total',
                label: 'Total',
                type: 'subtotal',
                sign: 'credit',
                terms: [{ row: 'sales', sign: 1 }],
            },
        ],
        columns: [
            {
                key: 'now',
                label: 'Tahun ini',
                kind: 'period',
                shift: { years: 0, months: 0 },
            },
            {
                key: 'prev',
                label: 'Tahun lalu',
                kind: 'period',
                shift: { years: -1, months: 0 },
            },
        ],
    },
};

const routes = (
    permissions = ['reports.read', 'report-definitions.manage'],
) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/accounts`]: () => json(page([])),
    [`GET ${base}/report-definitions`]: () => json(page([definition])),
    [`GET ${base}/movement-classifications`]: () => json(page([])),
    [`GET ${base}/report-definitions/${defId}`]: () =>
        json({ data: definition }),
    [`GET ${base}/report-definitions/${defId}/result`]: () =>
        json({
            data: {
                definition: { id: defId, key: 'income', version: 4 },
                parameters: {},
                functional_currency: 'IDR',
                restricted: false,
                columns: [],
                rows: [],
                unmapped_accounts: [],
                coverage_status: 'complete',
                coverage_from: null,
                coverage_to: null,
                coverage_granularity: 'transactions',
            },
        }),
});

describe('report definition editor', () => {
    afterEach(() => vi.restoreAllMocks());

    it('creates a definition from rows and columns and opens it', async () => {
        const api = mockApi({
            ...routes(),
            [`POST ${base}/report-definitions`]: () =>
                json({ data: definition }, 201),
        });

        renderApp(newPath);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Kunci'), 'income');
        await typist.type(screen.getByLabelText('Nama'), 'Laba Rugi');
        await typist.type(screen.getByLabelText('Kunci baris 1'), 'sales');
        await typist.type(screen.getByLabelText('Label baris 1'), 'Penjualan');
        await typist.clear(screen.getByLabelText('Kunci kolom 1'));
        await typist.type(screen.getByLabelText('Kunci kolom 1'), 'now');
        await typist.click(
            screen.getByRole('button', { name: 'Buat definisi' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/report-definitions`)).toHaveLength(
                1,
            ),
        );
        expect(api.called(`POST ${base}/report-definitions`)[0]?.body).toEqual({
            key: 'income',
            name: 'Laba Rugi',
            kind: 'custom',
            publish_on_period_close: false,
            publish_on_year_close: false,
            structure: {
                basis: 'movement',
                rows: [
                    {
                        key: 'sales',
                        label: 'Penjualan',
                        type: 'accounts',
                        sign: 'debit',
                        accounts: [{ type: 'class', class: 'asset' }],
                    },
                ],
                columns: [
                    {
                        key: 'now',
                        label: 'Periode',
                        kind: 'period',
                        shift: { years: 0, months: 0 },
                    },
                ],
            },
        });
        expect(
            await screen.findByRole('heading', { name: 'Laba Rugi' }),
        ).toBeInTheDocument();
    });

    it('loads the structure of the current version and saves a change with the lock version', async () => {
        const api = mockApi({
            ...routes(),
            [`PUT ${base}/report-definitions/${defId}`]: () =>
                json({ data: { ...definition, current_version: 4 } }),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        expect(
            await screen.findByText(/Versi saat ini: 3/),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Kunci')).toBeDisabled();
        expect(screen.getByLabelText('Label baris 1')).toHaveValue('Penjualan');
        expect(screen.getByLabelText('Mundur tahun (kolom 2)')).toHaveValue(
            '1',
        );

        await typist.clear(screen.getByLabelText('Label baris 1'));
        await typist.type(screen.getByLabelText('Label baris 1'), 'Pendapatan');
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() =>
            expect(
                api.called(`PUT ${base}/report-definitions/${defId}`),
            ).toHaveLength(1),
        );

        const body = api.called(`PUT ${base}/report-definitions/${defId}`)[0]
            ?.body as {
            lock_version: number;
            structure: {
                rows: { label: string; terms?: unknown }[];
                columns: { shift: { years: number } }[];
            };
        };

        expect(body.lock_version).toBe(4);
        expect(body.structure.rows[0]?.label).toBe('Pendapatan');
        // A subtotal keeps its terms and a shifted column its shift.
        expect(body.structure.rows[1]?.terms).toEqual([
            { row: 'sales', sign: 1 },
        ]);
        expect(body.structure.columns[1]?.shift.years).toBe(-1);
    });

    it('turns the account detail of a row on and sends it only for rows that list accounts (RP-FR-01)', async () => {
        const api = mockApi({
            ...routes(),
            [`PUT ${base}/report-definitions/${defId}`]: () =>
                json({ data: definition }),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        const detail = await screen.findByRole('checkbox', {
            name: 'Rinci akun pada baris 1',
        });

        expect(detail).not.toBeChecked();
        // A subtotal has nothing to list.
        expect(
            screen.queryByRole('checkbox', { name: 'Rinci akun pada baris 2' }),
        ).not.toBeInTheDocument();

        await typist.click(detail);
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() =>
            expect(
                api.called(`PUT ${base}/report-definitions/${defId}`),
            ).toHaveLength(1),
        );

        const body = api.called(`PUT ${base}/report-definitions/${defId}`)[0]
            ?.body as {
            structure: { rows: { account_detail?: boolean }[] };
        };

        expect(body.structure.rows[0]?.account_detail).toBe(true);
        expect(body.structure.rows[1]).not.toHaveProperty('account_detail');
    });

    it('shows when the definition is published automatically and saves the change (RP-FR-14)', async () => {
        const api = mockApi({
            ...routes(),
            [`PUT ${base}/report-definitions/${defId}`]: () =>
                json({ data: definition }),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        const onPeriod = await screen.findByRole('checkbox', {
            name: 'Saat periode ditutup',
        });
        const onYear = screen.getByRole('checkbox', {
            name: 'Saat tahun buku ditutup',
        });

        expect(onPeriod).toBeChecked();
        expect(onYear).not.toBeChecked();

        await typist.click(onPeriod);
        await typist.click(onYear);
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() =>
            expect(
                api.called(`PUT ${base}/report-definitions/${defId}`),
            ).toHaveLength(1),
        );

        expect(
            api.called(`PUT ${base}/report-definitions/${defId}`)[0]?.body,
        ).toMatchObject({
            publish_on_period_close: false,
            publish_on_year_close: true,
            lock_version: 4,
        });
    });

    it('puts the server messages on the row they belong to (RP-AC-07)', async () => {
        mockApi({
            ...routes(),
            [`PUT ${base}/report-definitions/${defId}`]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'structure.rows.0.key': [
                                'Kunci baris tidak valid.',
                            ],
                            'structure.rows.1.terms.0.row': [
                                'Suku merujuk baris yang tidak ada.',
                            ],
                            'structure.columns.1.shift': [
                                'Geser harus nol atau negatif.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Simpan perubahan' }),
        );

        expect(
            await screen.findByText('Kunci baris tidak valid.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Suku merujuk baris yang tidak ada.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Geser harus nol atau negatif.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Periksa pesan pada baris dan kolom/),
        ).toBeInTheDocument();
    });

    it('reorders and removes rows', async () => {
        mockApi(routes());

        renderApp(editPath);
        const typist = userEvent.setup();

        await screen.findByText(/Versi saat ini: 3/);
        await typist.click(
            screen.getByRole('button', { name: 'Turunkan baris 1' }),
        );

        expect(screen.getByLabelText('Kunci baris 1')).toHaveValue('total');
        expect(screen.getByLabelText('Kunci baris 2')).toHaveValue('sales');

        await typist.click(
            screen.getByRole('button', { name: 'Hapus baris 1' }),
        );

        expect(screen.queryByLabelText('Kunci baris 2')).toBeNull();
        expect(screen.getByLabelText('Kunci baris 1')).toHaveValue('sales');
    });

    it('offers the editor only to those who may manage definitions', async () => {
        mockApi(routes(['reports.read']));

        const { router } = renderApp(newPath);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });

    it('links to the editor from the list and the viewer for managers', async () => {
        mockApi(routes());

        renderApp(listPath);

        expect(
            await screen.findByRole('link', { name: 'Definisi baru' }),
        ).toHaveAttribute('href', `${listPath}/new`);

        await userEvent.click(
            await screen.findByRole('link', { name: 'Laba Rugi' }),
        );

        expect(
            await screen.findByRole('link', { name: 'Ubah definisi' }),
        ).toHaveAttribute('href', editPath);
    });
});
