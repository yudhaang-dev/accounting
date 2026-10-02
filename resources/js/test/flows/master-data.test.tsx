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

const typeId = '01JDIMTYPE0000000000000001';
const projectId = '01JDIMTYPE0000000000000002';
const jakarta = '01JDIMVALUE000000000000001';
const selatan = '01JDIMVALUE000000000000002';
const bandung = '01JDIMVALUE000000000000003';

const dimensionType = (overrides: Record<string, unknown> = {}) => ({
    id: typeId,
    key: 'branch',
    label: 'Cabang',
    is_hierarchical: true,
    leaf_only: false,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const dimensionValue = (overrides: Record<string, unknown> = {}) => ({
    id: jakarta,
    dimension_type_id: typeId,
    code: 'JKT',
    name: 'Jakarta',
    parent_id: null,
    is_leaf: false,
    valid_from: null,
    valid_to: null,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const values = [
    dimensionValue({}),
    dimensionValue({
        id: selatan,
        code: 'JKT-S',
        name: 'Jakarta Selatan',
        parent_id: jakarta,
        is_leaf: true,
        valid_from: '2026-01-01',
        valid_to: '2026-12-31',
    }),
    dimensionValue({
        id: bandung,
        code: 'BDG',
        name: 'Bandung',
        is_leaf: true,
        status: 'inactive',
    }),
];

const dimensionRoutes = (permissions: string[]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/dimension-types`]: () =>
        list([
            dimensionType(),
            dimensionType({
                id: projectId,
                key: 'project',
                label: 'Proyek',
                is_hierarchical: false,
            }),
        ]),
    [`GET ${base}/dimension-values`]: (call: {
        query: Record<string, string>;
    }) =>
        list(call.query['filter[dimension_type_id]'] === typeId ? values : []),
});

describe('dimensions', () => {
    const path = `${home}/dimensions`;

    it('lists the types and the values of the chosen one as a tree, hiding inactive values', async () => {
        mockApi(dimensionRoutes(['dimensions.read']));

        renderApp(path);

        expect(await screen.findByText('Jakarta Selatan')).toBeInTheDocument();
        expect(screen.getByText('Jakarta')).toHaveStyle({
            paddingInlineStart: '0px',
        });
        expect(screen.getByText('Jakarta Selatan')).toHaveStyle({
            paddingInlineStart: '20px',
        });
        expect(
            screen.getByRole('button', { name: /Proyek/ }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Bandung')).not.toBeInTheDocument();
        expect(screen.getByText('2026-01-01 – 2026-12-31')).toBeInTheDocument();

        await userEvent.setup().click(
            screen.getByRole('checkbox', {
                name: 'Tampilkan yang nonaktif',
            }),
        );

        expect(await screen.findByText('Bandung')).toBeInTheDocument();
    });

    it('asks the values of the type that is chosen', async () => {
        const api = mockApi(dimensionRoutes(['dimensions.read']));

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Jakarta Selatan');
        await typist.click(screen.getByRole('button', { name: /Proyek/ }));

        expect(await screen.findByText('Belum ada nilai')).toBeInTheDocument();
        expect(
            api
                .called(`GET ${base}/dimension-values`)
                .map((call) => call.query['filter[dimension_type_id]']),
        ).toEqual([typeId, projectId]);
    });

    it('offers no editing to someone who may only read', async () => {
        mockApi(dimensionRoutes(['dimensions.read']));

        renderApp(path);

        await screen.findByText('Jakarta Selatan');
        expect(
            screen.queryByRole('button', { name: 'Tambah tipe' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Tambah nilai' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^Aksi untuk/ }),
        ).not.toBeInTheDocument();
    });

    it('adds a value under a parent of the same type', async () => {
        const create = `POST ${base}/dimension-values`;
        const api = mockApi({
            ...dimensionRoutes(['dimensions.read', 'dimensions.manage']),
            [create]: () =>
                json(
                    {
                        data: dimensionValue({
                            id: '01JDIMVALUE000000000000009',
                            code: 'JKT-U',
                        }),
                    },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah nilai' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Kode'), 'JKT-U');
        await typist.type(
            within(dialog).getByLabelText('Nama'),
            'Jakarta Utara',
        );
        await typist.click(
            await within(dialog).findByRole('combobox', { name: 'Induk' }),
        );
        // Only active values are offered as parent: the inactive Bandung is not.
        expect(
            screen.queryByRole('option', { name: 'BDG · Bandung' }),
        ).not.toBeInTheDocument();
        await typist.click(
            await screen.findByRole('option', { name: 'JKT · Jakarta' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            dimension_type_id: typeId,
            code: 'JKT-U',
            name: 'Jakarta Utara',
            parent_id: jakarta,
            valid_from: null,
            valid_to: null,
        });
    });

    it('shows the server complaint next to the field', async () => {
        mockApi({
            ...dimensionRoutes(['dimensions.read', 'dimensions.manage']),
            [`POST ${base}/dimension-values`]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        errors: {
                            code: [
                                'Kode JKT sudah dipakai di tipe dimensi ini.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah nilai' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Kode'), 'JKT');
        await typist.type(within(dialog).getByLabelText('Nama'), 'Lagi');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText(
                'Kode JKT sudah dipakai di tipe dimensi ini.',
            ),
        ).toBeInTheDocument();
    });

    it('does not offer a value under itself or its own descendants when editing', async () => {
        mockApi(dimensionRoutes(['dimensions.read', 'dimensions.manage']));

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Jakarta Selatan');
        await rowAction(typist, 'JKT', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('combobox', { name: 'Induk' }),
        );

        expect(
            screen.queryByRole('option', { name: 'JKT · Jakarta' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'JKT-S · Jakarta Selatan' }),
        ).not.toBeInTheDocument();
    });

    it('sends the lock_version when editing', async () => {
        const update = `PATCH ${base}/dimension-values/${selatan}`;
        const api = mockApi({
            ...dimensionRoutes(['dimensions.read', 'dimensions.manage']),
            [update]: () => json({ data: values[1] }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Jakarta Selatan');
        await rowAction(typist, 'JKT-S', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        await typist.clear(within(dialog).getByLabelText('Nama'));
        await typist.type(within(dialog).getByLabelText('Nama'), 'Jaksel');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toMatchObject({
            lock_version: 1,
            name: 'Jaksel',
            parent_id: jakarta,
        });
    });

    it('asks before deactivating a value, and deletes only after confirmation', async () => {
        const deactivate = `POST ${base}/dimension-values/${selatan}/deactivate`;
        const destroy = `DELETE ${base}/dimension-values/${selatan}`;
        const api = mockApi({
            ...dimensionRoutes(['dimensions.read', 'dimensions.manage']),
            [deactivate]: () =>
                json({ data: { ...values[1], status: 'inactive' } }),
            [destroy]: () => new Response(null, { status: 204 }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Jakarta Selatan');
        await rowAction(typist, 'JKT-S', 'Nonaktifkan');

        expect(api.called(deactivate)).toHaveLength(0);
        await confirmWith(typist, 'Nonaktifkan');
        await waitFor(() => expect(api.called(deactivate)).toHaveLength(1));

        await rowAction(typist, 'JKT-S', 'Hapus');

        expect(api.called(destroy)).toHaveLength(0);
        await confirmWith(typist, 'Hapus');
        await waitFor(() => expect(api.called(destroy)).toHaveLength(1));
    });

    it('creates a type and chooses it', async () => {
        const create = `POST ${base}/dimension-types`;
        const api = mockApi({
            ...dimensionRoutes(['dimensions.read', 'dimensions.manage']),
            [create]: () =>
                json(
                    {
                        data: dimensionType({
                            id: '01JDIMTYPE0000000000000009',
                            key: 'region',
                            label: 'Wilayah',
                            is_hierarchical: false,
                        }),
                    },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Tambah tipe' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Nama tipe'),
            'Wilayah',
        );
        await typist.type(within(dialog).getByLabelText('Kunci'), 'Bad Key');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText(/Mulai dengan huruf kecil/),
        ).toBeInTheDocument();
        expect(api.called(create)).toHaveLength(0);

        await typist.clear(within(dialog).getByLabelText('Kunci'));
        await typist.type(within(dialog).getByLabelText('Kunci'), 'region');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            key: 'region',
            label: 'Wilayah',
            is_hierarchical: false,
            leaf_only: false,
        });
    });

    it('sends someone without the permission back to the company home', async () => {
        mockApi(withPermissions([]));

        const { router } = renderApp(path);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(home);
        expect(
            screen.queryByRole('link', { name: 'Dimensi' }),
        ).not.toBeInTheDocument();
    });
});

const currency = (overrides: Record<string, unknown> = {}) => ({
    code: 'IDR',
    name: 'Rupiah',
    minor_unit: 2,
    scale: 2,
    rounding_mode: 'half_up',
    is_functional: true,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const usd = currency({ code: 'USD', name: 'US Dollar', is_functional: false });

describe('currencies', () => {
    const path = `${home}/currencies`;
    const routes = (permissions: string[]) => ({
        ...withPermissions(permissions),
        [`GET ${base}/currencies`]: () => list([currency(), usd]),
        [`GET ${base}/iso-currencies`]: () =>
            list([
                { code: 'IDR', name: 'Rupiah', minor_unit: 2 },
                { code: 'JPY', name: 'Yen', minor_unit: 0 },
                { code: 'USD', name: 'US Dollar', minor_unit: 2 },
            ]),
    });

    it('lists the activated currencies, marking the functional one', async () => {
        mockApi(routes(['currencies.read']));

        renderApp(path);

        expect(await screen.findByText('US Dollar')).toBeInTheDocument();
        expect(screen.getByText('Fungsional')).toBeInTheDocument();
        expect(screen.getAllByText('Ke atas bila setengah')).toHaveLength(2);
        expect(
            screen.queryByRole('button', { name: 'Aktifkan mata uang' }),
        ).not.toBeInTheDocument();
    });

    it('activates a currency from the ISO list with its minor unit as the scale', async () => {
        const create = `POST ${base}/currencies`;
        const api = mockApi({
            ...routes(['currencies.read', 'currencies.manage']),
            [create]: () =>
                json(
                    {
                        data: currency({
                            code: 'JPY',
                            name: 'Yen',
                            scale: 0,
                            is_functional: false,
                        }),
                    },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Aktifkan mata uang' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            await within(dialog).findByRole('combobox', { name: 'Mata uang' }),
        );
        // Those already activated are not offered again.
        expect(
            screen.queryByRole('option', { name: 'USD · US Dollar' }),
        ).not.toBeInTheDocument();
        await typist.click(
            await screen.findByRole('option', { name: 'JPY · Yen' }),
        );

        expect(
            within(dialog).getByLabelText('Skala (angka desimal)'),
        ).toHaveValue('0');
        expect(
            within(dialog).getByText(
                'ISO menyarankan 0 angka di belakang koma.',
            ),
        ).toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('button', { name: 'Aktifkan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            currency: 'JPY',
            scale: 0,
            rounding_mode: 'half_up',
        });
    });

    it('changes scale and rounding of a currency with its lock_version', async () => {
        const update = `PATCH ${base}/currencies/USD`;
        const api = mockApi({
            ...routes(['currencies.read', 'currencies.manage']),
            [update]: () => json({ data: usd }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('US Dollar');
        await rowAction(typist, 'USD', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        await typist.clear(
            within(dialog).getByLabelText('Skala (angka desimal)'),
        );
        await typist.type(
            within(dialog).getByLabelText('Skala (angka desimal)'),
            '4',
        );
        await typist.click(
            within(dialog).getByRole('combobox', { name: 'Pembulatan' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'Selalu ke bawah' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            scale: 4,
            rounding_mode: 'down',
        });
    });

    it('refuses a scale outside 0 to 6 before asking the server', async () => {
        const update = `PATCH ${base}/currencies/USD`;
        const api = mockApi({
            ...routes(['currencies.read', 'currencies.manage']),
            [update]: () => json({ data: usd }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('US Dollar');
        await rowAction(typist, 'USD', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        await typist.clear(
            within(dialog).getByLabelText('Skala (angka desimal)'),
        );
        await typist.type(
            within(dialog).getByLabelText('Skala (angka desimal)'),
            '9',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        expect(
            await within(dialog).findByText('Isi angka 0 sampai 6.'),
        ).toBeInTheDocument();
        expect(api.called(update)).toHaveLength(0);
    });

    it('never offers to deactivate the functional currency, and asks before deactivating another', async () => {
        const deactivate = `POST ${base}/currencies/USD/deactivate`;
        const api = mockApi({
            ...routes(['currencies.read', 'currencies.manage']),
            [deactivate]: () => json({ data: { ...usd, status: 'inactive' } }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('US Dollar');
        await typist.click(
            screen.getByRole('button', { name: 'Aksi untuk IDR' }),
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Ubah' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Nonaktifkan' }),
        ).not.toBeInTheDocument();
        await typist.keyboard('{Escape}');

        await rowAction(typist, 'USD', 'Nonaktifkan');
        expect(api.called(deactivate)).toHaveLength(0);
        await confirmWith(typist, 'Nonaktifkan');
        await waitFor(() => expect(api.called(deactivate)).toHaveLength(1));
    });
});

const rateTypeRow = (overrides: Record<string, unknown> = {}) => ({
    id: '01JRATETYPE000000000000001',
    key: 'central_bank',
    label: 'Kurs tengah BI',
    max_age_days: 7,
    status: 'active',
    lock_version: 1,
    ...overrides,
});

const rateRow = (overrides: Record<string, unknown> = {}) => ({
    id: '01JRATE00000000000000000001',
    rate_type_id: '01JRATETYPE000000000000001',
    rate_type: 'central_bank',
    currency: 'USD',
    effective_date: '2026-03-10',
    rate: '16250.5',
    version: 1,
    source: 'manual',
    actor_type: 'user',
    actor_id: '1',
    created_at: '2026-03-10T08:00:00+07:00',
    ...overrides,
});

describe('exchange rates', () => {
    const path = `${home}/exchange-rates`;
    const routes = (
        permissions: string[],
        types: unknown[] = [rateTypeRow()],
    ) => ({
        ...withPermissions(permissions),
        [`GET ${base}/rate-types`]: () => list(types),
        [`GET ${base}/currencies`]: () => list([currency(), usd]),
        [`GET ${base}/exchange-rates`]: () =>
            list([
                rateRow(),
                rateRow({
                    id: '01JRATE00000000000000000002',
                    effective_date: '2026-03-09',
                    version: 2,
                    source: 'csv',
                    rate: '0.000064',
                }),
            ]),
    });

    it('lists the rates with their type, version and source, in the reader format', async () => {
        mockApi(routes(['exchange-rates.read']));

        renderApp(path);

        expect(await screen.findByText('16.250,5')).toBeInTheDocument();
        expect(screen.getByText('0,000064')).toBeInTheDocument();
        expect(screen.getAllByText('Kurs tengah BI').length).toBeGreaterThan(0);
        expect(screen.getByText('v2')).toBeInTheDocument();
        expect(screen.getByText('CSV')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Catat kurs' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Jenis kurs' }),
        ).toBeInTheDocument();
    });

    it('asks for the filters that were chosen, with the history only on request', async () => {
        const api = mockApi(routes(['exchange-rates.read']));

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('16.250,5');
        await typist.click(screen.getByRole('combobox', { name: 'Mata uang' }));
        await typist.click(await screen.findByRole('option', { name: 'USD' }));
        await typist.click(
            screen.getByRole('checkbox', { name: 'Tampilkan semua versi' }),
        );

        await waitFor(() => {
            const last = api.called(`GET ${base}/exchange-rates`).at(-1)?.query;

            expect(last?.['filter[currency]']).toBe('USD');
            expect(last?.['filter[include_superseded]']).toBe('true');
        });
        expect(
            api.called(`GET ${base}/exchange-rates`)[0]?.query[
                'filter[include_superseded]'
            ],
        ).toBeUndefined();
    });

    it('points to the rate types when there are none', async () => {
        mockApi(routes(['exchange-rates.read', 'exchange-rates.manage'], []));

        renderApp(path);

        expect(
            await screen.findByText('Belum ada jenis kurs'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Catat kurs' }),
        ).toBeDisabled();
    });

    it('records a rate with the exact decimals that were typed', async () => {
        const create = `POST ${base}/exchange-rates`;
        const api = mockApi({
            ...routes(['exchange-rates.read', 'exchange-rates.manage']),
            [create]: () => json({ data: rateRow({ rate: '16300' }) }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Catat kurs' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            await within(dialog).findByRole('combobox', { name: 'Mata uang' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'USD · US Dollar' }),
        );
        await typist.type(
            within(dialog).getByLabelText('Kurs'),
            '16300,123456789012',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Catat' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            rate_type: 'central_bank',
            currency: 'USD',
            effective_date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
            rate: '16300.123456789012',
        });
    });

    it('refuses a rate of zero before asking the server', async () => {
        const create = `POST ${base}/exchange-rates`;
        const api = mockApi({
            ...routes(['exchange-rates.read', 'exchange-rates.manage']),
            [create]: () => json({ data: rateRow() }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Catat kurs' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(await within(dialog).findByLabelText('Kurs'), '0');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Catat' }),
        );

        expect(
            await within(dialog).findByText('Isi kurs lebih besar dari nol.'),
        ).toBeInTheDocument();
        expect(api.called(create)).toHaveLength(0);
    });

    it('corrects a rate as a new version, keeping its type, currency and date', async () => {
        const create = `POST ${base}/exchange-rates`;
        const api = mockApi({
            ...routes(['exchange-rates.read', 'exchange-rates.manage']),
            [create]: () =>
                json({ data: rateRow({ version: 3, rate: '16400' }) }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('16.250,5');
        await rowAction(typist, 'USD 2026-03-10', 'Koreksi kurs');
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByText(/versi 2/)).toBeInTheDocument();
        expect(
            within(dialog).getByRole('combobox', { name: 'Jenis kurs' }),
        ).toBeDisabled();
        expect(
            within(dialog).getByRole('combobox', { name: 'Mata uang' }),
        ).toBeDisabled();

        await typist.clear(within(dialog).getByLabelText('Kurs'));
        await typist.type(within(dialog).getByLabelText('Kurs'), '16400');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan koreksi' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            rate_type: 'central_bank',
            currency: 'USD',
            effective_date: '2026-03-10',
            rate: '16400',
        });
    });

    it('imports a CSV file as text/csv and reports the outcome', async () => {
        const importPath = `POST ${base}/exchange-rates/import`;
        const api = mockApi({
            ...routes(['exchange-rates.read', 'exchange-rates.manage']),
            [importPath]: () =>
                json({ data: { created: 2, unchanged: 1 } }, 201),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Impor CSV' }),
        );
        const dialog = await screen.findByRole('dialog');
        const csv =
            'rate_type,currency,effective_date,rate\ncentral_bank,USD,2026-03-10,16250.50\n';

        await typist.upload(
            within(dialog).getByLabelText('Berkas CSV'),
            new File([csv], 'rates.csv', { type: 'text/csv' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor' }),
        );

        await waitFor(() => expect(api.called(importPath)).toHaveLength(1));
        // The file travels as the text it holds, not as a form upload.
        expect(api.called(importPath)[0]?.body).toBe(csv);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('lists every problem of a rejected file by line', async () => {
        const importPath = `POST ${base}/exchange-rates/import`;
        mockApi({
            ...routes(['exchange-rates.read', 'exchange-rates.manage']),
            [importPath]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        errors: {
                            'rows.3.currency': [
                                'Mata uang JPY belum diaktifkan untuk perusahaan ini.',
                            ],
                            'rows.4.rate': ['Format rate tidak valid.'],
                        },
                    },
                    422,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Impor CSV' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.upload(
            within(dialog).getByLabelText('Berkas CSV'),
            new File(['x'], 'rates.csv', { type: 'text/csv' }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor' }),
        );

        const problems = await within(dialog).findByRole('list', {
            name: 'Masalah pada berkas',
        });

        expect(
            within(problems).getByText(
                /Baris 3:\s+currency: Mata uang JPY belum diaktifkan/,
            ),
        ).toBeInTheDocument();
        expect(
            within(problems).getByText(
                /Baris 4:\s+rate: Format rate tidak valid/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/Berkas ditolak seluruhnya/),
        ).toBeInTheDocument();
    });

    it('asks to choose a file first', async () => {
        const importPath = `POST ${base}/exchange-rates/import`;
        const api = mockApi({
            ...routes(['exchange-rates.read', 'exchange-rates.manage']),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Impor CSV' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor' }),
        );

        expect(
            await within(dialog).findByText('Pilih berkas CSV lebih dulu.'),
        ).toBeInTheDocument();
        expect(api.called(importPath)).toHaveLength(0);
    });
});

describe('rate types', () => {
    const path = `${home}/exchange-rates/types`;

    it('lists the types with their age limit and adds one', async () => {
        const create = `POST ${base}/rate-types`;
        const api = mockApi({
            ...withPermissions([
                'exchange-rates.read',
                'exchange-rates.manage',
            ]),
            [`GET ${base}/rate-types`]: () =>
                list([
                    rateTypeRow(),
                    rateTypeRow({
                        id: '01JRATETYPE000000000000002',
                        key: 'tax',
                        label: 'Kurs pajak',
                        max_age_days: null,
                    }),
                ]),
            [create]: () =>
                json(
                    { data: rateTypeRow({ key: 'spot', label: 'Spot' }) },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        expect(await screen.findByText('7 hari')).toBeInTheDocument();
        expect(screen.getByText('Tanpa batas')).toBeInTheDocument();

        await typist.click(
            screen.getByRole('button', { name: 'Tambah jenis kurs' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Nama'), 'Spot');
        await typist.type(within(dialog).getByLabelText('Kunci'), 'spot');
        await typist.type(
            within(dialog).getByLabelText('Batas umur kurs (hari)'),
            '3',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            key: 'spot',
            label: 'Spot',
            max_age_days: 3,
        });
    });

    it('sends an emptied age limit as no limit when editing', async () => {
        const update = `PATCH ${base}/rate-types/01JRATETYPE000000000000001`;
        const api = mockApi({
            ...withPermissions([
                'exchange-rates.read',
                'exchange-rates.manage',
            ]),
            [`GET ${base}/rate-types`]: () => list([rateTypeRow()]),
            [update]: () => json({ data: rateTypeRow({ max_age_days: null }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kurs tengah BI');
        await rowAction(typist, 'Kurs tengah BI', 'Ubah');
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByLabelText('Kunci')).toBeDisabled();

        await typist.clear(
            within(dialog).getByLabelText('Batas umur kurs (hari)'),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            lock_version: 1,
            label: 'Kurs tengah BI',
            max_age_days: null,
        });
    });

    it('asks before deactivating a type', async () => {
        const deactivate = `POST ${base}/rate-types/01JRATETYPE000000000000001/deactivate`;
        const api = mockApi({
            ...withPermissions([
                'exchange-rates.read',
                'exchange-rates.manage',
            ]),
            [`GET ${base}/rate-types`]: () => list([rateTypeRow()]),
            [deactivate]: () =>
                json({ data: rateTypeRow({ status: 'inactive' }) }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await screen.findByText('Kurs tengah BI');
        await rowAction(typist, 'Kurs tengah BI', 'Nonaktifkan');

        expect(api.called(deactivate)).toHaveLength(0);
        await confirmWith(typist, 'Nonaktifkan');
        await waitFor(() => expect(api.called(deactivate)).toHaveLength(1));
    });
});

describe('menu', () => {
    it('shows the master data screens only to those who may open them', async () => {
        mockApi(withPermissions(['dimensions.read', 'exchange-rates.read']));

        renderApp(home);

        expect(
            await screen.findByRole('link', { name: 'Dimensi' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Kurs' })).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Mata uang' }),
        ).not.toBeInTheDocument();
    });
});
