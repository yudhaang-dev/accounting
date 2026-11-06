import { screen, waitFor, within } from '@testing-library/react';
import { confirmWith } from '@/test/actions';
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

describe('opening a company that is still being set up', () => {
    const asAdmin = (status: string) => {
        const server = setupServer();

        server.state.status = status;

        return {
            ...server.routes,
            'GET /api/v1/me/workspaces': () =>
                json({
                    data: [
                        {
                            ...workspace,
                            permissions: ['company.activate', 'accounts.read'],
                        },
                    ],
                }),
        };
    };

    it('goes straight to the wizard for someone who may finish it', async () => {
        mockApi(asAdmin('setup'));

        const { router } = renderApp('/');

        expect(
            await screen.findByRole('heading', {
                name: 'Menyiapkan PT Toko Budi',
            }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}/setup`,
        );
    });

    it('also does so from the company home, so the wizard cannot be missed', async () => {
        mockApi(asAdmin('setup'));

        const { router } = renderApp(`/w/${workspace.company_id}`);

        await screen.findByRole('heading', { name: 'Menyiapkan PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}/setup`,
        );
    });

    it('leaves an active company on its home', async () => {
        mockApi(asAdmin('active'));

        const { router } = renderApp(`/w/${workspace.company_id}`);

        expect(
            await screen.findByRole('heading', { name: 'PT Toko Budi' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });

    it('keeps the home, with its note, for someone who cannot finish the setup', async () => {
        mockApi({ ...setupServer().routes });

        const { router } = renderApp(`/w/${workspace.company_id}`);

        expect(
            await screen.findByText('Perusahaan masih dalam penyiapan'),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });
});

describe('wizard progress', () => {
    const years = [
        {
            id: 'fy1',
            name: 'FY2026',
            start_date: '2026-01-01',
            end_date: '2026-12-31',
            period_count: 12,
        },
    ];

    it('points at the chart of accounts first', async () => {
        mockApi(setupServer().routes);

        renderApp(setupPath);

        expect(await screen.findByText('Langkah 2 dari 4')).toBeInTheDocument();
        expect(
            screen.getByRole('listitem', { current: 'step' }),
        ).toHaveTextContent('Bagan akun');
    });

    it('moves on to the calendar once the chart is in', async () => {
        const server = setupServer();

        server.state.chart = 4;
        mockApi(server.routes);

        renderApp(setupPath);

        expect(await screen.findByText('Langkah 3 dari 4')).toBeInTheDocument();
        expect(
            screen.getByRole('listitem', { current: 'step' }),
        ).toHaveTextContent('Kalender fiskal');
    });

    it('ends on the activation when both are done', async () => {
        const server = setupServer();

        server.state.chart = 4;
        server.state.calendar = {
            id: 'cal',
            pattern: 'monthly',
            start_month: 1,
            lock_version: 1,
        };
        server.state.years = years;
        mockApi(server.routes);

        renderApp(setupPath);

        expect(await screen.findByText('Langkah 4 dari 4')).toBeInTheDocument();
        expect(
            screen.getByRole('listitem', { current: 'step' }),
        ).toHaveTextContent('Aktivasi');
    });
});

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
        await confirmWith(typist, 'Aktifkan');

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
        await confirmWith(typist, 'Aktifkan');

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

const pack = {
    key: 'general',
    name: 'Umum',
    description: 'Bagan akun umum dan jenis transaksi harian.',
    version: 1,
    components: [
        {
            id: 'chart_of_accounts',
            kind: 'chart_of_accounts',
            template: 'general-id',
            name: 'Umum Indonesia',
            version: 1,
            items: [
                ['1111', 'Kas Kecil', true, false, null],
                ['1113', 'Bank Khusus', false, true, null],
                [
                    '1114',
                    'Dana dalam Perjalanan',
                    false,
                    false,
                    'Kode dipakai akun lain.',
                ],
            ].map(([key, label, exists, optional, conflict]) => ({
                key,
                label,
                exists,
                optional,
                conflict,
            })),
        },
        {
            id: 'party_roles',
            kind: 'party_roles',
            template: 'default',
            name: 'Peran bawaan',
            version: 1,
            items: [
                {
                    key: 'customer',
                    label: 'Pelanggan',
                    exists: false,
                    optional: false,
                    conflict: null,
                },
            ],
        },
    ],
};

/** The setup server with a pack released and an install that gives the company its chart. */
function packServer() {
    const server = setupServer();

    server.routes[`GET ${base}/template-packs`] = () =>
        json({
            data: [
                {
                    key: pack.key,
                    name: pack.name,
                    description: pack.description,
                    version: 1,
                    additive: false,
                    profiles: [],
                    components: pack.components.map(
                        ({ id, kind, template }) => ({
                            id,
                            kind,
                            template,
                        }),
                    ),
                },
            ],
        });
    server.routes[`GET ${base}/template-packs/general`] = () =>
        json({ data: pack });
    server.routes[`POST ${base}/template-packs/general/install`] = (call: {
        body: unknown;
    }) => {
        const body = call.body as { components: string[] };

        server.state.chart = 3;

        return json(
            {
                data: {
                    pack: 'general',
                    version: 1,
                    components: body.components.map((id) => ({
                        id,
                        template: 'x',
                        created: ['a', 'b'],
                        skipped: ['c'],
                    })),
                },
            },
            201,
        );
    };

    return server;
}

const reviewed = 'Saya telah meninjau isi paket ini dan ingin memasangnya.';

describe('template pack step', () => {
    it('installs nothing until the pack is reviewed and confirmed (X-AC-06)', async () => {
        const api = mockApi(packServer().routes);

        renderApp(setupPath);
        const typist = userEvent.setup();
        const install = await screen.findByRole('button', {
            name: 'Pasang paket',
        });

        expect(install).toBeDisabled();
        // 1111 exists and 1114 is in conflict, so only 1113 and the role are created.
        expect(
            screen.getByText('1 dari 3 item akan dibuat.'),
        ).toBeInTheDocument();
        expect(screen.getByText('1 bentrok')).toBeInTheDocument();

        await typist.click(screen.getByRole('checkbox', { name: reviewed }));
        await typist.click(install);

        expect(await screen.findByText('Paket terpasang')).toBeInTheDocument();
        expect(screen.getAllByText(/2 dibuat, 1 dilewati/)).toHaveLength(2);
        expect(
            api.called(`POST ${base}/template-packs/general/install`)[0]?.body,
        ).toEqual({
            confirmed: true,
            components: ['chart_of_accounts', 'party_roles'],
            // The conflict is always left out.
            exclude: { chart_of_accounts: ['1114'] },
        });
    });

    it('leaves out the component and the optional item that were unticked', async () => {
        const api = mockApi(packServer().routes);

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', {
                name: 'Pasang komponen Peran bawaan',
            }),
        );
        await typist.click(
            screen.getByRole('checkbox', { name: 'Pasang Bank Khusus' }),
        );
        await typist.click(screen.getByRole('checkbox', { name: reviewed }));
        await typist.click(
            screen.getByRole('button', { name: 'Pasang paket' }),
        );

        await screen.findByText('Paket terpasang');
        expect(
            api.called(`POST ${base}/template-packs/general/install`)[0]?.body,
        ).toEqual({
            confirmed: true,
            components: ['chart_of_accounts'],
            exclude: { chart_of_accounts: ['1113', '1114'] },
        });
    });

    it('does not let an item that exists or is in conflict be picked', async () => {
        mockApi(packServer().routes);

        renderApp(setupPath);

        for (const label of ['Kas Kecil', 'Dana dalam Perjalanan']) {
            expect(
                await screen.findByRole('checkbox', {
                    name: `Pasang ${label}`,
                }),
            ).toHaveAttribute('aria-disabled', 'true');
        }
    });

    it('shows what the server refused and keeps the review', async () => {
        const server = packServer();

        server.routes[`POST ${base}/template-packs/general/install`] = () =>
            json(
                {
                    message: 'Invalid',
                    code: 'VALIDATION_FAILED',
                    errors: { components: ['Komponen tidak dikenal.'] },
                },
                422,
            );
        mockApi(server.routes);

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', { name: reviewed }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Pasang paket' }),
        );

        expect(
            await screen.findByText('Komponen tidak dikenal.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Pasang paket' }),
        ).toBeInTheDocument();
    });

    it('still offers the chart on its own', async () => {
        mockApi(packServer().routes);

        renderApp(setupPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Hanya bagan akun' }),
        );

        expect(
            await screen.findByRole('button', { name: 'Pasang bagan akun' }),
        ).toBeInTheDocument();
    });

    it('does not offer an add-on pack to a company without a chart', async () => {
        const server = packServer();

        server.routes[`GET ${base}/template-packs`] = () =>
            json({
                data: [
                    {
                        key: pack.key,
                        name: pack.name,
                        description: pack.description,
                        version: 1,
                        additive: false,
                        profiles: [],
                        components: [],
                    },
                    {
                        key: 'addon-inventory',
                        name: 'Persediaan dan HPP',
                        description: 'Tambahan',
                        version: 1,
                        additive: true,
                        profiles: [],
                        components: [],
                    },
                ],
            });
        mockApi(server.routes);

        renderApp(setupPath);

        expect(await screen.findByText(pack.name)).toBeInTheDocument();
        expect(
            screen.queryByText('Persediaan dan HPP'),
        ).not.toBeInTheDocument();
    });

    it('falls back to the chart when no pack is released', async () => {
        const server = packServer();

        server.routes[`GET ${base}/template-packs`] = () => json({ data: [] });
        mockApi(server.routes);

        renderApp(setupPath);

        expect(
            await screen.findByRole('button', { name: 'Pasang bagan akun' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Paket jenis usaha' }),
        ).not.toBeInTheDocument();
    });
});
