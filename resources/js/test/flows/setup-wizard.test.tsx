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

const template = {
    key: 'general-id',
    name: 'Umum Indonesia',
    version: 1,
    accounts: [
        ['1000', 'ASET', null, false],
        ['1111', 'Kas Kecil', '1000', false],
        ['1113', 'Bank Khusus', '1000', true],
        ['1114', 'Dana dalam Perjalanan', '1000', true],
    ].map(([code, name, parent, optional]) => ({
        code,
        name,
        class: 'asset',
        normal: parent ? 'debit' : null,
        parent,
        optional,
        note: null,
    })),
};

/** A company in `setup`, whose chart, calendar and status change as the wizard makes its calls. */
function setupServer(overrides: Record<string, () => Response> = {}) {
    const state = {
        status: 'setup',
        chart: 0,
        calendar: null as null | {
            id: string;
            pattern: string;
            start_month: number;
            lock_version: number;
        },
        years: [] as unknown[],
    };

    const routes = {
        'GET /api/v1/me': () => json({ data: account }),
        'GET /api/v1/me/workspaces': () => json({ data: [workspace] }),
        [`GET ${base}`]: () =>
            json({ data: { ...company, status: state.status } }),
        [`GET ${base}/accounts`]: () =>
            json({ data: [], links: {}, meta: { total: state.chart } }),
        [`GET ${base}/account-templates`]: () =>
            json({
                data: [
                    {
                        key: template.key,
                        name: template.name,
                        version: 1,
                        account_count: template.accounts.length,
                    },
                ],
            }),
        [`GET ${base}/account-templates/general-id`]: () =>
            json({ data: template }),
        [`POST ${base}/accounts/import-template`]: (call: {
            body: unknown;
        }) => {
            const exclude = (call.body as { exclude: string[] }).exclude;

            state.chart = template.accounts.length - exclude.length;

            return json(
                { data: { template: 'general-id', created: state.chart } },
                201,
            );
        },
        [`GET ${base}/fiscal-calendar`]: () =>
            state.calendar
                ? json({ data: state.calendar })
                : json({ message: 'Not found.' }, 404),
        [`POST ${base}/fiscal-calendar`]: () => {
            state.calendar = {
                id: 'cal',
                pattern: 'monthly',
                start_month: 1,
                lock_version: 1,
            };

            return json({ data: state.calendar }, 201);
        },
        [`GET ${base}/fiscal-years`]: () => json({ data: state.years }),
        [`POST ${base}/fiscal-years`]: () => {
            state.years = [
                {
                    id: 'fy1',
                    name: 'FY2026',
                    start_date: '2026-01-01',
                    end_date: '2026-12-31',
                    period_count: 12,
                },
            ];

            return json({ data: state.years }, 201);
        },
        [`POST ${base}/activate`]: () => {
            if (state.chart === 0 || state.years.length === 0) {
                return json(
                    {
                        message: 'Not ready',
                        code: 'COMPANY_NOT_READY',
                        errors: { chart_of_accounts: ['Belum ada akun aset.'] },
                    },
                    422,
                );
            }

            state.status = 'active';

            return json({ data: { ...company, status: 'active' } });
        },
        ...overrides,
    };

    return { state, routes };
}

const setupPath = `/w/${workspace.company_id}/setup`;

describe('company setup wizard', () => {
    it('creates a workspace with its company and continues to the setup', async () => {
        const server = setupServer();
        let created = false;
        const api = mockApi({
            ...server.routes,
            'GET /api/v1/me/workspaces': () =>
                json({ data: created ? [workspace] : [] }),
            'POST /api/v1/workspaces': () => {
                created = true;

                return json(
                    {
                        data: {
                            id: workspace.id,
                            name: workspace.name,
                            company_id: workspace.company_id,
                            company_name: workspace.company_name,
                            permissions: [],
                        },
                    },
                    201,
                );
            },
        });

        renderApp('/workspaces');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('link', { name: 'Buat ruang kerja baru' }),
        );
        await typist.type(
            await screen.findByLabelText('Nama ruang kerja'),
            'Toko Budi',
        );
        await typist.type(
            screen.getByLabelText('Nama perusahaan'),
            'PT Toko Budi',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Buat ruang kerja' }),
        );

        expect(
            await screen.findByRole('heading', {
                name: 'Menyiapkan PT Toko Budi',
            }),
        ).toBeInTheDocument();
        expect(api.called('POST /api/v1/workspaces')[0]?.body).toEqual({
            workspace_name: 'Toko Budi',
            company_name: 'PT Toko Budi',
            functional_currency: 'IDR',
            timezone: 'Asia/Jakarta',
            default_locale: 'id',
        });
    });

    it('shows the server error next to the field it belongs to', async () => {
        const server = setupServer();

        mockApi({
            ...server.routes,
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
            'POST /api/v1/workspaces': () =>
                json(
                    {
                        message: 'Invalid',
                        errors: {
                            company_name: ['Nama perusahaan sudah dipakai.'],
                        },
                    },
                    422,
                ),
        });

        renderApp('/workspaces/new');
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Nama ruang kerja'),
            'Toko Budi',
        );
        await typist.type(
            screen.getByLabelText('Nama perusahaan'),
            'PT Toko Budi',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Buat ruang kerja' }),
        );

        expect(
            await screen.findByText('Nama perusahaan sudah dipakai.'),
        ).toBeInTheDocument();
    });

    it('installs nothing until the template is reviewed and confirmed (X-AC-03)', async () => {
        const server = setupServer();
        const api = mockApi(server.routes);

        renderApp(setupPath);
        const typist = userEvent.setup();
        const install = await screen.findByRole('button', {
            name: 'Pasang bagan akun',
        });

        expect(install).toBeDisabled();
        expect(
            screen.getByText('4 dari 4 akun akan dibuat.'),
        ).toBeInTheDocument();

        // Leave one optional account out, then confirm.
        await typist.click(
            screen.getByRole('checkbox', { name: 'Pasang akun 1114' }),
        );
        expect(
            screen.getByText('3 dari 4 akun akan dibuat.'),
        ).toBeInTheDocument();
        await typist.click(
            screen.getByRole('checkbox', {
                name: 'Saya telah meninjau daftar akun ini dan ingin memasangnya.',
            }),
        );
        await typist.click(install);

        expect(
            await screen.findByText('Bagan akun terpasang'),
        ).toBeInTheDocument();
        expect(
            api.called(`POST ${base}/accounts/import-template`)[0]?.body,
        ).toEqual({
            template: 'general-id',
            confirmed: true,
            exclude: ['1114'],
        });
    });

    it('only lets optional leaf accounts be left out', async () => {
        mockApi(setupServer().routes);

        renderApp(setupPath);

        expect(
            await screen.findByRole('checkbox', { name: 'Pasang akun 1000' }),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.getByRole('checkbox', { name: 'Pasang akun 1111' }),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.getByRole('checkbox', { name: 'Pasang akun 1113' }),
        ).not.toHaveAttribute('aria-disabled', 'true');
    });

    it('starts the fiscal year in the month that was chosen', async () => {
        const server = setupServer();
        const api = mockApi({
            ...server.routes,
            [`POST ${base}/fiscal-calendar`]: (call: { body: unknown }) => {
                const { start_month } = call.body as { start_month: number };

                server.state.calendar = {
                    id: 'cal',
                    pattern: 'monthly',
                    start_month,
                    lock_version: 1,
                };

                return json({ data: server.state.calendar }, 201);
            },
        });

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('combobox', {
                name: 'Tahun fiskal dimulai pada bulan',
            }),
        );
        await typist.click(await screen.findByRole('option', { name: 'Juli' }));
        await typist.click(
            screen.getByRole('button', { name: 'Simpan kalender' }),
        );

        await typist.clear(await screen.findByLabelText('Tahun mulai'));
        await typist.type(screen.getByLabelText('Tahun mulai'), '2026');
        await typist.click(
            screen.getByRole('button', { name: 'Buat tahun fiskal' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/fiscal-years`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/fiscal-calendar`)[0]?.body).toEqual({
            pattern: 'monthly',
            start_month: 7,
        });
        // The first year begins on the 1st of the chosen month.
        expect(api.called(`POST ${base}/fiscal-years`)[0]?.body).toEqual({
            start_date: '2026-07-01',
            count: 1,
        });
    });

    it('sets up the calendar, then the fiscal years', async () => {
        const server = setupServer();
        const api = mockApi(server.routes);

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Simpan kalender' }),
        );

        expect(await screen.findByLabelText('Tahun mulai')).toBeInTheDocument();
        await typist.clear(screen.getByLabelText('Tahun mulai'));
        await typist.type(screen.getByLabelText('Tahun mulai'), '2026');
        await typist.click(
            screen.getByRole('button', { name: 'Buat tahun fiskal' }),
        );

        expect(
            await screen.findByText('Kalender fiskal siap'),
        ).toBeInTheDocument();
        expect(screen.getByText(/FY2026/)).toBeInTheDocument();
        expect(api.called(`POST ${base}/fiscal-calendar`)[0]?.body).toEqual({
            pattern: 'monthly',
            start_month: 1,
        });
        expect(api.called(`POST ${base}/fiscal-years`)[0]?.body).toEqual({
            start_date: '2026-01-01',
            count: 1,
        });
    });

    it('resumes where it stopped: an existing calendar skips straight to the years', async () => {
        const server = setupServer();

        server.state.calendar = {
            id: 'cal',
            pattern: 'monthly',
            start_month: 7,
            lock_version: 1,
        };
        mockApi(server.routes);

        renderApp(setupPath);

        expect(await screen.findByLabelText('Tahun mulai')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Simpan kalender' }),
        ).not.toBeInTheDocument();
    });

    it('keeps activation locked until the chart and the calendar are in place', async () => {
        mockApi(setupServer().routes);

        renderApp(setupPath);

        expect(
            await screen.findByRole('button', { name: 'Aktifkan perusahaan' }),
        ).toBeDisabled();
        expect(
            screen.getByText('Selesaikan langkah sebelumnya terlebih dahulu.'),
        ).toBeInTheDocument();
    });

    it('explains what is missing when the server refuses to activate', async () => {
        const server = setupServer();

        // The screen believes it is ready (state seen earlier), but the server disagrees.
        server.state.chart = 4;
        server.state.calendar = {
            id: 'cal',
            pattern: 'monthly',
            start_month: 1,
            lock_version: 1,
        };
        server.state.years = [
            {
                id: 'fy1',
                name: 'FY2026',
                start_date: '2026-01-01',
                end_date: '2026-12-31',
                period_count: 12,
            },
        ];
        mockApi({
            ...server.routes,
            [`POST ${base}/activate`]: () =>
                json(
                    {
                        message: 'Not ready',
                        code: 'COMPANY_NOT_READY',
                        errors: { chart_of_accounts: ['Belum ada akun aset.'] },
                    },
                    422,
                ),
        });

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Aktifkan perusahaan' }),
        );

        const alert = await screen.findByRole('alert');

        expect(
            within(alert).getByText('Perusahaan belum dapat diaktifkan.'),
        ).toBeInTheDocument();
        expect(
            within(alert).getByText(/Belum ada akun aset\./),
        ).toBeInTheDocument();
    });

    it('activates the company and opens it', async () => {
        const server = setupServer();

        server.state.chart = 4;
        server.state.calendar = {
            id: 'cal',
            pattern: 'monthly',
            start_month: 1,
            lock_version: 1,
        };
        server.state.years = [
            {
                id: 'fy1',
                name: 'FY2026',
                start_date: '2026-01-01',
                end_date: '2026-12-31',
                period_count: 12,
            },
        ];
        const api = mockApi(server.routes);

        const { router } = renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Aktifkan perusahaan' }),
        );

        await waitFor(() =>
            expect(router.state.location.pathname).toBe(
                `/w/${workspace.company_id}`,
            ),
        );
        expect(await screen.findByText('Aktif')).toBeInTheDocument();
        expect(api.called(`POST ${base}/activate`)).toHaveLength(1);
    });

    it('sends an already active company away from the wizard', async () => {
        const server = setupServer();

        server.state.status = 'active';
        mockApi(server.routes);

        const { router } = renderApp(`/w/${workspace.company_id}`);

        await screen.findByText('Aktif');
        await router.navigate({
            to: '/w/$companyId/setup',
            params: { companyId: workspace.company_id },
        });

        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });

    it('edits the profile during setup', async () => {
        const server = setupServer({
            [`PATCH ${base}`]: () =>
                json({
                    data: {
                        ...company,
                        name: 'PT Toko Budi Jaya',
                        lock_version: 2,
                    },
                }),
        });
        const api = mockApi(server.routes);

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(await screen.findByRole('button', { name: 'Ubah' }));
        const name = await screen.findByLabelText('Nama perusahaan');

        await typist.clear(name);
        await typist.type(name, 'PT Toko Budi Jaya');
        await typist.click(
            screen.getByRole('button', { name: 'Simpan profil' }),
        );

        expect(
            await screen.findByText(/PT Toko Budi Jaya · IDR/),
        ).toBeInTheDocument();
        expect(api.called(`PATCH ${base}`)[0]?.body).toMatchObject({
            lock_version: 1,
            name: 'PT Toko Budi Jaya',
            functional_currency: 'IDR',
        });
    });
});
