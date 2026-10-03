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
import { pickDate, todayIso } from '@/test/pickers';

type Routes = Parameters<typeof mockApi>[0];

const base = `/api/v1/companies/${workspace.company_id}`;
const entriesPath = `/w/${workspace.company_id}/entries`;
const journalsPath = `/w/${workspace.company_id}/journals`;

const withPermissions = (permissions: string[]): Routes => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const cash = '01JACCOUNT0000000000000001';
const sales = '01JACCOUNT0000000000000002';
const journalId = '01JJOURNAL0000000000000001';

const journal = () => ({
    id: journalId,
    number: 'JU/2026/10/0001',
    accounting_date: '2026-10-02',
    period_id: '01JPERIOD0000000000000001',
    document_date: null,
    description: 'Setoran modal',
    source: 'entry',
    transaction_type_key: 'general',
    transaction_type_version: 1,
    references: null,
    reverses_journal_id: null,
    entry_id: '01JENTRY00000000000000001',
    posted_by: 1,
    source_override_reason: null,
    posted_at: '2026-10-02T09:00:00+07:00',
    scope_restricted: false,
    lines: [
        {
            line_number: 1,
            account_id: cash,
            debit: '1500000',
            credit: '0',
            currency: 'IDR',
            transaction_amount: '1500000',
            exchange_rate: '1',
            dimension_set_id: '01JSET0000000000000000001',
            dimensions: {},
            description: null,
        },
        {
            line_number: 2,
            account_id: sales,
            debit: '0',
            credit: '1500000',
            currency: 'IDR',
            transaction_amount: '1500000',
            exchange_rate: '1',
            dimension_set_id: '01JSET0000000000000000001',
            dimensions: {},
            description: null,
        },
    ],
});

const entry = (overrides: Record<string, unknown> = {}) => ({
    id: '01JENTRY00000000000000001',
    number: 'JU-2026-0001',
    status: 'posted',
    source: 'manual',
    transaction_type_key: 'general',
    transaction_type_version: 1,
    accounting_date: '2026-10-02',
    document_date: null,
    description: 'Setoran modal',
    lines: [
        {
            account_id: cash,
            debit: '1500000',
            credit: '0',
            description: null,
            dimensions: {},
        },
        {
            account_id: sales,
            debit: '0',
            credit: '1500000',
            description: 'Modal awal',
            dimensions: {},
        },
    ],
    values: null,
    approval: null,
    reverses_journal_id: null,
    reason: null,
    replaces_entry_id: null,
    auto_reverse_on: null,
    auto_reversal_status: null,
    auto_reversal_error: null,
    auto_reversal_entry_id: null,
    created_by: 1,
    journal_id: journalId,
    journal_number: 'JU/2026/10/0001',
    posted_at: '2026-10-02T09:00:00+07:00',
    decisions: [],
    lock_version: 1,
    ...overrides,
});

const page = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const accounts = () =>
    page([
        {
            id: cash,
            code: '1111',
            name: 'Kas Kecil',
            class: 'asset',
            normal_balance: 'debit',
            parent_id: null,
            is_leaf: true,
            status: 'active',
            lock_version: 1,
        },
        {
            id: sales,
            code: '4100',
            name: 'Penjualan',
            class: 'revenue',
            normal_balance: 'credit',
            parent_id: null,
            is_leaf: true,
            status: 'active',
            lock_version: 1,
        },
    ]);

const types = () =>
    json({
        data: [
            {
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
            },
        ],
    });

const attachments = () => json({ data: [] });

describe('reversing a journal', () => {
    const reverse = `POST ${base}/journals/${journalId}/reverse`;
    const draft = entry({
        id: '01JENTRY00000000000000002',
        number: 'PB-2026-0001',
        status: 'draft',
        reverses_journal_id: journalId,
        reason: 'Salah akun',
        journal_id: null,
        journal_number: null,
        posted_at: null,
    });

    const journalRoutes = (
        permissions: string[],
        overrides: Routes = {},
    ): Routes => ({
        ...withPermissions(permissions),
        [`GET ${base}/journals/${journalId}`]: () => json({ data: journal() }),
        ...overrides,
    });

    it('asks for a date and a reason, then opens the reversal draft', async () => {
        const api = mockApi(
            journalRoutes(
                ['entries.read', 'journals.read', 'entries.reverse'],
                {
                    [reverse]: () => json({ data: draft }, 201),
                    [`GET ${base}/entries/${draft.id}`]: () =>
                        json({ data: draft }),
                    [`GET ${base}/entries/${draft.id}/attachments`]:
                        attachments,
                    [`GET ${base}/transaction-types`]: types,
                    [`GET ${base}/accounts`]: accounts,
                },
            ),
        );

        renderApp(`${journalsPath}/${journalId}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Balik jurnal' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(
            within(dialog).getByRole('button', {
                name: 'Buat draf pembalikan',
            }),
        );

        // The reason is required, so nothing is sent yet.
        expect(
            await within(dialog).findByText('Wajib diisi'),
        ).toBeInTheDocument();
        expect(api.called(reverse)).toHaveLength(0);

        await typist.type(
            within(dialog).getByLabelText('Alasan'),
            'Salah akun',
        );
        await typist.click(
            within(dialog).getByRole('button', {
                name: 'Buat draf pembalikan',
            }),
        );

        await waitFor(() => expect(api.called(reverse)).toHaveLength(1));
        expect(api.called(reverse)[0]?.body).toEqual({
            reversal_date: todayIso(),
            reason: 'Salah akun',
        });
        expect(
            await screen.findByRole('heading', { name: 'Entri PB-2026-0001' }),
        ).toBeInTheDocument();
        expect(screen.getByText(/Salah akun/)).toBeInTheDocument();
    });

    it('says when the journal was reversed already', async () => {
        mockApi(
            journalRoutes(
                ['entries.read', 'journals.read', 'entries.reverse'],
                {
                    [reverse]: () =>
                        json(
                            {
                                message: 'Jurnal sudah dibalik.',
                                code: 'INVALID_STATE_TRANSITION',
                                errors: {
                                    journal_id: [
                                        'Jurnal JU/2026/10/0001 sudah dibalik.',
                                    ],
                                },
                            },
                            409,
                        ),
                },
            ),
        );

        renderApp(`${journalsPath}/${journalId}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Balik jurnal' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Alasan'), 'Salah');
        await typist.click(
            within(dialog).getByRole('button', {
                name: 'Buat draf pembalikan',
            }),
        );

        expect(
            await within(dialog).findByText(/sudah dibalik/, {
                exact: false,
            }),
        ).toBeInTheDocument();
    });

    it('puts a closed period next to the date', async () => {
        mockApi(
            journalRoutes(
                ['entries.read', 'journals.read', 'entries.reverse'],
                {
                    [reverse]: () =>
                        json(
                            {
                                message: 'Data tidak valid.',
                                errors: {
                                    reversal_date: [
                                        'Periode Oktober 2026 sudah ditutup.',
                                    ],
                                },
                            },
                            422,
                        ),
                },
            ),
        );

        renderApp(`${journalsPath}/${journalId}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Balik jurnal' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(within(dialog).getByLabelText('Alasan'), 'Salah');
        await typist.click(
            within(dialog).getByRole('button', {
                name: 'Buat draf pembalikan',
            }),
        );

        expect(
            await within(dialog).findByText(
                'Periode Oktober 2026 sudah ditutup.',
            ),
        ).toBeInTheDocument();
    });

    it('is not offered without the permission to reverse', async () => {
        mockApi(journalRoutes(['journals.read']));

        renderApp(`${journalsPath}/${journalId}`);

        await screen.findByRole('heading', { name: /JU\/2026\/10\/0001/ });
        expect(
            screen.queryByRole('button', { name: 'Balik jurnal' }),
        ).not.toBeInTheDocument();
    });

    it('offers the same action on the posted entry, and a replacement', async () => {
        mockApi({
            ...withPermissions([
                'entries.read',
                'entries.create',
                'entries.reverse',
            ]),
            [`GET ${base}/entries/${entry().id}`]: () =>
                json({ data: entry() }),
            [`GET ${base}/entries/${entry().id}/attachments`]: attachments,
            [`GET ${base}/transaction-types`]: types,
            [`GET ${base}/accounts`]: accounts,
        });

        renderApp(`${entriesPath}/${entry().id}`);

        expect(
            await screen.findByRole('button', { name: 'Balik jurnal' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Buat entri pengganti' }),
        ).toHaveAttribute('href', expect.stringContaining('replaces='));
    });

    it('links a reversal entry to the journal it reverses and does not offer to replace it', async () => {
        mockApi({
            ...withPermissions([
                'entries.read',
                'entries.create',
                'journals.read',
            ]),
            [`GET ${base}/entries/${draft.id}`]: () =>
                json({ data: { ...draft, status: 'posted' } }),
            [`GET ${base}/entries/${draft.id}/attachments`]: attachments,
            [`GET ${base}/transaction-types`]: types,
            [`GET ${base}/accounts`]: accounts,
        });

        renderApp(`${entriesPath}/${draft.id}`);

        expect(
            await screen.findByRole('link', { name: 'Buka jurnal' }),
        ).toHaveAttribute('href', `${journalsPath}/${journalId}`);
        expect(
            screen.queryByRole('link', { name: 'Buat entri pengganti' }),
        ).not.toBeInTheDocument();
    });
});

describe('replacement entry', () => {
    const formRoutes = (overrides: Routes = {}): Routes => ({
        ...withPermissions(['entries.read', 'entries.create']),
        [`GET ${base}/entries/${entry().id}`]: () => json({ data: entry() }),
        [`GET ${base}/transaction-types`]: types,
        [`GET ${base}/accounts`]: accounts,
        ...overrides,
    });

    it('starts as a copy of the posted entry and links back to it', async () => {
        const create = `POST ${base}/entries`;
        const created = entry({
            id: '01JENTRY00000000000000003',
            status: 'draft',
        });
        const api = mockApi(
            formRoutes({
                [create]: () => json({ data: created }, 201),
                [`GET ${base}/entries/${created.id}`]: () =>
                    json({ data: created }),
                [`GET ${base}/entries/${created.id}/attachments`]: attachments,
            }),
        );

        renderApp(`${entriesPath}/new?replaces=${entry().id}`);
        const typist = userEvent.setup();

        expect(
            await screen.findByText(/Entri ini menggantikan JU-2026-0001/),
        ).toBeInTheDocument();
        expect(await screen.findByLabelText('Keterangan')).toHaveValue(
            'Setoran modal',
        );
        expect(screen.getByLabelText('Debit Baris 1')).toHaveValue('1.500.000');

        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            transaction_type_key: 'general',
            replaces_entry_id: entry().id,
            description: 'Setoran modal',
            lines: [
                { account_id: cash, debit: '1500000' },
                { account_id: sales, credit: '1500000' },
            ],
        });
    });

    it('refuses to replace an entry that is not posted', async () => {
        mockApi(
            formRoutes({
                [`GET ${base}/entries/${entry().id}`]: () =>
                    json({ data: entry({ status: 'draft' }) }),
            }),
        );

        renderApp(`${entriesPath}/new?replaces=${entry().id}`);

        expect(
            await screen.findByText(
                'Hanya entri yang sudah diposting yang dapat diganti.',
            ),
        ).toBeInTheDocument();
    });
});

describe('scheduled reversal', () => {
    const create = `POST ${base}/entries`;

    it('takes the date the system should reverse the entry on', async () => {
        const api = mockApi({
            ...withPermissions(['entries.read', 'entries.create']),
            [create]: () => json({ data: entry({ status: 'draft' }) }, 201),
            [`GET ${base}/entries/${entry().id}`]: () =>
                json({ data: entry({ status: 'draft' }) }),
            [`GET ${base}/entries/${entry().id}/attachments`]: attachments,
            [`GET ${base}/transaction-types`]: types,
            [`GET ${base}/accounts`]: accounts,
        });

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Keterangan'), 'Akrual');
        await pickDate(
            typist,
            screen.getByRole('button', { name: /Balik otomatis pada/ }),
            '2030-11-30',
        );
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun Baris 1' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '1111 · Kas Kecil' }),
        );
        await typist.type(screen.getByLabelText('Debit Baris 1'), '100');
        await typist.click(
            screen.getByRole('combobox', { name: 'Akun Baris 2' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: '4100 · Penjualan' }),
        );
        await typist.type(screen.getByLabelText('Kredit Baris 2'), '100');
        await typist.click(screen.getByRole('button', { name: 'Simpan draf' }));

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toMatchObject({
            auto_reverse_on: '2030-11-30',
        });
    });

    it('says when a posted entry will be reversed', async () => {
        mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/entries/${entry().id}`]: () =>
                json({
                    data: entry({
                        auto_reverse_on: '2026-11-01',
                        auto_reversal_status: 'pending',
                    }),
                }),
            [`GET ${base}/entries/${entry().id}/attachments`]: attachments,
            [`GET ${base}/transaction-types`]: types,
            [`GET ${base}/accounts`]: accounts,
        });

        renderApp(`${entriesPath}/${entry().id}`);

        expect(
            await screen.findByText(
                'Akan dibalik otomatis pada 2026-11-01 setelah diposting.',
            ),
        ).toBeInTheDocument();
    });

    it('shows a failed reversal as an exception with its cause, never silently', async () => {
        mockApi({
            ...withPermissions(['entries.read', 'entries.reverse']),
            [`GET ${base}/entries/${entry().id}`]: () =>
                json({
                    data: entry({
                        auto_reverse_on: '2026-11-01',
                        auto_reversal_status: 'failed',
                        auto_reversal_error:
                            'Periode November 2026 sudah ditutup.',
                    }),
                }),
            [`GET ${base}/entries/${entry().id}/attachments`]: attachments,
            [`GET ${base}/transaction-types`]: types,
            [`GET ${base}/accounts`]: accounts,
        });

        renderApp(`${entriesPath}/${entry().id}`);

        const alert = await screen.findByRole('alert');

        expect(
            within(alert).getByText('Pembalikan terjadwal 2026-11-01 gagal'),
        ).toBeInTheDocument();
        expect(
            within(alert).getByText('Periode November 2026 sudah ditutup.'),
        ).toBeInTheDocument();
        // It can be reversed by hand from here.
        expect(
            within(alert).getByRole('button', { name: 'Balik jurnal' }),
        ).toBeInTheDocument();
    });

    it('lists the failed reversals of the company on the entry list', async () => {
        const failed = entry({
            auto_reverse_on: '2026-11-01',
            auto_reversal_status: 'failed',
            auto_reversal_error: 'Periode November 2026 sudah ditutup.',
        });
        const api = mockApi({
            ...withPermissions(['entries.read']),
            [`GET ${base}/entries`]: (call) =>
                call.query['filter[auto_reversal_status]'] === 'failed'
                    ? page([failed])
                    : page([
                          failed,
                          entry({
                              id: '01JENTRY00000000000000007',
                              number: 'JU-2026-0007',
                          }),
                      ]),
            [`GET ${base}/transaction-types`]: types,
        });

        renderApp(entriesPath);
        const typist = userEvent.setup();

        expect(
            await screen.findByText(
                '1 pembalikan terjadwal gagal dan menunggu tindakan.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('JU-2026-0007')).toBeInTheDocument();

        await typist.click(screen.getByRole('button', { name: 'Tampilkan' }));

        await waitFor(() =>
            expect(screen.queryByText('JU-2026-0007')).not.toBeInTheDocument(),
        );
        expect(
            screen.getByText(/Pembalikan tanggal 2026-11-01 gagal\./),
        ).toBeInTheDocument();
        expect(
            api
                .called(`GET ${base}/entries`)
                .some(
                    (call) =>
                        call.query['filter[auto_reversal_status]'] ===
                            'failed' && call.query.per_page === '25',
                ),
        ).toBe(true);
    });
});
