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
import { pickDate } from '@/test/pickers';

type Routes = Parameters<typeof mockApi>[0];

const base = `/api/v1/companies/${workspace.company_id}`;
const listPath = `/w/${workspace.company_id}/recurring-schedules`;

const withPermissions = (permissions: string[]): Routes => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const read = ['recurring-schedules.read'];
const manage = ['recurring-schedules.read', 'recurring-schedules.manage'];

const rent = '01JACCOUNT0000000000000001';
const cash = '01JACCOUNT0000000000000002';

const page = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const schedule = (overrides: Record<string, unknown> = {}) => ({
    id: '01JSCHEDULE00000000000001',
    name: 'Sewa kantor',
    transaction_type_key: 'general',
    transaction_type_version: null,
    template: {
        description: 'Sewa bulanan',
        document_date: null,
        lines: [
            {
                account_id: rent,
                debit: '5000000',
                credit: '0',
                description: null,
                dimensions: {},
            },
            {
                account_id: cash,
                debit: '0',
                credit: '5000000',
                description: null,
                dimensions: {},
            },
        ],
    },
    frequency: 'monthly',
    interval_count: 1,
    start_date: '2026-10-01',
    end_date: null,
    initial_status: 'draft',
    status: 'active',
    next_run_on: '2026-11-01',
    last_run_on: '2026-10-01',
    last_entry_id: '01JENTRY00000000000000001',
    last_error: null,
    last_error_at: null,
    created_by: 1,
    lock_version: 3,
    ...overrides,
});

const generalType = {
    id: '01JTYPE000000000000000001',
    key: 'general',
    label: 'Jurnal Umum',
    numbering: {
        pattern: 'JU/{yyyy}/{seq}',
        padding: 4,
        reset: 'yearly',
        policy: 'on_create',
    },
    versions: [
        {
            version: 1,
            mode: 'free_lines',
            status: 'published',
            fields: null,
            posting_rules: null,
            allowed_currencies: null,
            rate_type_key: null,
            allow_rate_input: false,
        },
    ],
    lock_version: 1,
};

const payrollType = {
    ...generalType,
    id: '01JTYPE000000000000000002',
    key: 'payroll_summary',
    label: 'Ringkasan Payroll',
    versions: [
        {
            ...generalType.versions[0],
            mode: 'structured',
            version: 2,
        },
    ],
};

const accountRow = (id: string, code: string, name: string) => ({
    id,
    code,
    name,
    class: 'expense',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    lock_version: 1,
});

const lookups: Routes = {
    [`GET ${base}/transaction-types`]: () =>
        json({ data: [generalType, payrollType] }),
    [`GET ${base}/accounts`]: () =>
        page([
            accountRow(rent, '5200', 'Beban Sewa'),
            accountRow(cash, '1111', 'Kas Kecil'),
        ]),
    [`GET ${base}/currencies`]: () => page([]),
    [`GET ${base}/transaction-types/${payrollType.id}/versions/2/schema`]: () =>
        json({
            data: {
                type: 'object',
                properties: {
                    gross_salary: {
                        title: 'Total gaji bruto',
                        type: 'string',
                        'x-scale': 'currency',
                    },
                },
                required: ['gross_salary'],
            },
        }),
};

const list = (
    permissions: string[],
    schedules: unknown[],
    overrides: Routes = {},
) =>
    mockApi({
        ...withPermissions(permissions),
        ...lookups,
        [`GET ${base}/recurring-schedules`]: () => page(schedules),
        ...overrides,
    });

describe('recurring schedule list', () => {
    it('lists the schedules with their rhythm, next run and last entry', async () => {
        list(read, [
            schedule(),
            schedule({
                id: '01JSCHEDULE00000000000002',
                name: 'Penyusutan',
                interval_count: 3,
                frequency: 'weekly',
                status: 'paused',
            }),
        ]);

        renderApp(listPath);

        expect(await screen.findByText('Sewa kantor')).toBeInTheDocument();
        expect(screen.getByText('Setiap bulan')).toBeInTheDocument();
        expect(screen.getByText('Setiap 3 minggu')).toBeInTheDocument();
        expect(screen.getAllByText('2026-11-01')).toHaveLength(2);
        expect(
            screen.getAllByRole('link', { name: '2026-10-01' }),
        ).toHaveLength(2);
        expect(
            within(screen.getByRole('table')).getByText('Dijeda'),
        ).toBeInTheDocument();
        // A reader cannot create or change anything.
        expect(
            screen.queryByRole('link', { name: 'Jadwal baru' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Aksi untuk Sewa kantor' }),
        ).not.toBeInTheDocument();
    });

    it('makes a stuck schedule visible with its cause, not hidden in a log', async () => {
        list(manage, [
            schedule({
                last_error: 'Periode November 2026 sudah ditutup.',
                last_error_at: '2026-11-01T00:05:00+07:00',
            }),
            schedule({ id: '01JSCHEDULE00000000000002', name: 'Penyusutan' }),
        ]);

        renderApp(listPath);

        const banner = await screen.findByText(/1 jadwal tersendat/);

        expect(banner).toBeVisible();
        expect(
            screen.getByText(
                /Gagal membuat entri 2026-11-01: Periode November 2026 sudah ditutup\./,
            ),
        ).toBeVisible();
        expect(screen.getByText('Tersendat')).toBeVisible();
    });

    it('narrows the list by status', async () => {
        list(read, [
            schedule(),
            schedule({
                id: '01JSCHEDULE00000000000002',
                name: 'Penyusutan',
                status: 'paused',
            }),
        ]);

        renderApp(listPath);
        const typist = userEvent.setup();

        await screen.findByText('Sewa kantor');
        await typist.click(screen.getByRole('button', { name: 'Dijeda' }));

        expect(screen.queryByText('Sewa kantor')).not.toBeInTheDocument();
        expect(screen.getByText('Penyusutan')).toBeInTheDocument();
    });

    it('pauses a schedule without touching its template', async () => {
        const update = `PUT ${base}/recurring-schedules/${schedule().id}`;
        const api = list(manage, [schedule()], {
            [update]: () => json({ data: schedule({ status: 'paused' }) }),
        });

        renderApp(listPath);
        const typist = userEvent.setup();

        await rowAction(typist, 'Sewa kantor', 'Jeda');

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toMatchObject({
            lock_version: 3,
            name: 'Sewa kantor',
            status: 'paused',
            end_date: null,
            initial_status: 'draft',
            template: schedule().template,
        });
    });

    it('skips a date only after the user confirms', async () => {
        const skip = `POST ${base}/recurring-schedules/${schedule().id}/skip`;
        const api = list(
            manage,
            [schedule({ last_error: 'Periode ditutup.' })],
            { [skip]: () => json({ data: schedule() }) },
        );

        renderApp(listPath);
        const typist = userEvent.setup();

        await rowAction(typist, 'Sewa kantor', 'Lewati tanggal ini');

        expect(api.called(skip)).toHaveLength(0);

        await confirmWith(typist, 'Lewati');

        await waitFor(() => expect(api.called(skip)).toHaveLength(1));
    });

    it('invites the first schedule', async () => {
        list(manage, []);

        renderApp(listPath);

        expect(await screen.findByText('Belum ada jadwal')).toBeVisible();
        expect(
            screen.getAllByRole('link', { name: 'Jadwal baru' }).length,
        ).toBeGreaterThan(0);
    });
});

describe('recurring schedule form', () => {
    const create = `POST ${base}/recurring-schedules`;

    const fillLines = async (typist: ReturnType<typeof userEvent.setup>) => {
        await typist.type(
            await screen.findByLabelText('Keterangan'),
            'Sewa bulanan',
        );
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun Baris 1' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '5200 · Beban Sewa' }),
        );
        await typist.type(screen.getByLabelText('Debit Baris 1'), '5000000');
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun Baris 2' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '1111 · Kas Kecil' }),
        );
        await typist.type(screen.getByLabelText('Kredit Baris 2'), '5000000');
    };

    it('creates a schedule with a free-lines template', async () => {
        const api = mockApi({
            ...withPermissions(manage),
            ...lookups,
            [create]: () => json({ data: schedule() }, 201),
            [`GET ${base}/recurring-schedules`]: () => page([schedule()]),
        });

        renderApp(`${listPath}/new`);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Nama jadwal'),
            'Sewa kantor',
        );
        await pickDate(
            typist,
            screen.getByRole('button', { name: /Mulai tanggal/ }),
            '2030-01-01',
        );
        await typist.click(
            screen.getByRole('combobox', { name: 'Entri dibiarkan sebagai' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'Diajukan' }),
        );
        await fillLines(typist);
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            name: 'Sewa kantor',
            transaction_type_key: 'general',
            frequency: 'monthly',
            interval_count: 1,
            start_date: '2030-01-01',
            end_date: null,
            initial_status: 'submitted',
            transaction_type_version: null,
            template: {
                description: 'Sewa bulanan',
                document_date: null,
                lines: [
                    {
                        account_id: rent,
                        debit: '5000000',
                        credit: null,
                        description: null,
                        dimensions: {},
                    },
                    {
                        account_id: cash,
                        debit: null,
                        credit: '5000000',
                        description: null,
                        dimensions: {},
                    },
                ],
            },
        });
        // Back on the list afterwards.
        expect(await screen.findByText('Setiap bulan')).toBeInTheDocument();
    });

    it('draws a structured template from the schema of the version', async () => {
        const api = mockApi({
            ...withPermissions(manage),
            ...lookups,
            [create]: () => json({ data: schedule() }, 201),
            [`GET ${base}/recurring-schedules`]: () => page([]),
        });

        renderApp(`${listPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Nama jadwal'), 'Gaji');
        await typist.click(
            screen.getByRole('combobox', { name: 'Jenis transaksi' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'Ringkasan Payroll' }),
        );
        await typist.type(screen.getByLabelText('Keterangan'), 'Gaji bulanan');
        await typist.type(
            await screen.findByLabelText('Total gaji bruto'),
            '15000000',
        );
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            transaction_type_key: 'payroll_summary',
            transaction_type_version: 2,
            template: {
                description: 'Gaji bulanan',
                values: { gross_salary: '15000000' },
            },
        });
        expect(api.called(create)[0]?.body).not.toHaveProperty(
            'template.lines',
        );
    });

    it('puts a refusal of the template next to the line it belongs to', async () => {
        mockApi({
            ...withPermissions(manage),
            ...lookups,
            [create]: () =>
                json(
                    {
                        message: 'Data tidak valid.',
                        errors: {
                            'template.lines.0.account_id': [
                                'Akun tidak menerima posting.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(`${listPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Nama jadwal'), 'Sewa');
        await fillLines(typist);
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        expect(
            await screen.findByText('Akun tidak menerima posting.'),
        ).toBeInTheDocument();
    });

    it('edits a schedule: the rhythm and the start stay, the template changes', async () => {
        const update = `PUT ${base}/recurring-schedules/${schedule().id}`;
        const api = mockApi({
            ...withPermissions(manage),
            ...lookups,
            [`GET ${base}/recurring-schedules/${schedule().id}`]: () =>
                json({ data: schedule() }),
            [update]: () => json({ data: schedule() }),
            [`GET ${base}/recurring-schedules`]: () => page([schedule()]),
        });

        renderApp(`${listPath}/${schedule().id}/edit`);
        const typist = userEvent.setup();

        const name = await screen.findByLabelText('Nama jadwal');

        expect(name).toHaveValue('Sewa kantor');
        expect(screen.getByLabelText('Setiap berapa')).toBeDisabled();
        expect(
            screen.getByRole('combobox', { name: 'Jenis transaksi' }),
        ).toBeDisabled();

        await typist.clear(name);
        await typist.type(name, 'Sewa gedung');
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        const body = api.called(update)[0]?.body as Record<string, unknown>;

        expect(body).toMatchObject({ lock_version: 3, name: 'Sewa gedung' });
        // What cannot change after creation is not sent.
        expect(body).not.toHaveProperty('frequency');
        expect(body).not.toHaveProperty('start_date');
    });

    it('is closed to a reader', async () => {
        mockApi({ ...withPermissions(read), ...lookups });

        renderApp(`${listPath}/new`);

        await waitFor(() =>
            expect(
                screen.queryByRole('heading', { name: 'Jadwal baru' }),
            ).not.toBeInTheDocument(),
        );
    });
});
