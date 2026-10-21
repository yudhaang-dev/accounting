import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    openItemTotals,
    parseBalances,
    parseJournals,
    parseOpenItems,
} from '@/modules/general-ledger/conversion';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from '@/test/harness';
import { pickDate } from '@/test/pickers';

const base = `/api/v1/companies/${workspace.company_id}`;
const path = `/w/${workspace.company_id}/opening-balances`;

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

const accountRow = (
    id: string,
    code: string,
    name: string,
    overrides: Record<string, unknown> = {},
) => ({
    id,
    code,
    name,
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    allowed_sources: ['entry', 'subledger', 'system'],
    dimension_requirements: {},
    currency_restriction: 'any',
    restricted_currency: null,
    is_revaluable: false,
    lock_version: 1,
    ...overrides,
});

const cash = accountRow('01JACCOUNT0000000000000001', '1111', 'Kas Kecil');
const capital = accountRow('01JACCOUNT0000000000000002', '3110', 'Modal', {
    class: 'equity',
});
const receivable = accountRow('01JACCOUNT0000000000000003', '1121', 'Piutang');

const notFound = () => json({ message: 'Not found', code: 'NOT_FOUND' }, 404);

const batch = (overrides: Record<string, unknown> = {}) => ({
    id: '01JBATCH',
    source_key: 'legacy-2026-04',
    source_system: 'legacy',
    cutover_date: '2026-06-01',
    ledger_start_date: '2026-04-01',
    history_granularity: null,
    status: 'posted',
    journal_id: '01JJOURNAL000000000000001',
    journal_number: 'JU-2026-04-0001',
    line_count: 2,
    created_at: '2026-10-03T10:00:00Z',
    ...overrides,
});

const routes = (permissions: string[]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/accounts`]: () => list([cash, capital, receivable]),
});

const manage = ['journals.read', 'opening-balances.import', 'accounts.read'];

const csv = 'source_code,debit,credit\n1111,1000000.00,0\n3110,0,1000000.00\n';

describe('reading the source files', () => {
    it('brings each account to one side and drops accounts that net to zero', () => {
        const { lines, problems } = parseBalances(
            'source_code,debit,credit\n1111,100.00,0\n1111,50.00,0\n3110,0,150.00\n4110,10,10\n',
        );

        expect(problems).toEqual([]);
        expect(lines).toEqual([
            {
                source_account_code: '1111',
                debit: '150.00',
                credit: '0',
                description: null,
            },
            {
                source_account_code: '3110',
                debit: '0',
                credit: '150.00',
                description: null,
            },
        ]);
    });

    it('says which rows and columns it cannot read', () => {
        expect(parseBalances('code,debit,credit\n1,1,0').problems).toEqual([
            { line: 1, kind: 'column', detail: 'source_code' },
        ]);

        const { problems } = parseBalances(
            'source_code,debit,credit\n1111,1.234,50,0\n,5,0\n1112,abc,0\n',
        );

        expect(problems.map((problem) => [problem.line, problem.kind])).toEqual(
            [
                [3, 'code'],
                [4, 'amount'],
            ],
        );
    });

    it('groups history rows into journals by date and reference', () => {
        const { journals, problems } = parseJournals(
            [
                'date,journal,source_code,debit,credit',
                '2026-04-30,APR,1111,500.00,0',
                '2026-04-30,APR,4110,0,500.00',
                '2026-05-31,,1111,0,200.00',
                '2026-05-31,,5110,200.00,0',
                '30/04/2026,X,1111,1,0',
            ].join('\n'),
        );

        expect(problems).toEqual([
            { line: 6, kind: 'date', detail: '30/04/2026' },
        ]);
        expect(
            journals.map((journal) => [
                journal.accounting_date,
                journal.description,
                journal.lines.length,
            ]),
        ).toEqual([
            ['2026-04-30', 'APR', 2],
            ['2026-05-31', null, 2],
        ]);
    });
});

describe('opening balance wizard (GL-FR-11)', () => {
    const post = `POST ${base}/opening-balance-imports`;

    async function startWizard(permissions = manage) {
        let imported = false;
        const api = mockApi({
            ...routes(permissions),
            [`GET ${base}/opening-balance-imports`]: () =>
                imported ? json({ data: batch() }) : notFound(),
            [`GET ${base}/history-imports`]: () => notFound(),
            [post]: () => {
                imported = true;

                return json({ data: batch() }, 201);
            },
        });

        renderApp(path);

        return { api, typist: userEvent.setup() };
    }

    async function fillDetails(
        typist: ReturnType<typeof userEvent.setup>,
        start?: string,
    ) {
        await typist.type(
            await screen.findByLabelText('Kunci sumber'),
            'legacy-2026-04',
        );
        await pickDate(
            typist,
            screen.getByRole('button', { name: /Tanggal cutover/ }),
            '2026-06-01',
        );

        if (start) {
            await pickDate(
                typist,
                screen.getByRole('button', { name: /Awal histori/ }),
                start,
            );
        }

        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));
    }

    async function provide(
        typist: ReturnType<typeof userEvent.setup>,
        text: string,
    ) {
        const field = await screen.findByRole('textbox', {
            name: /Neraca saldo awal/,
        });

        await typist.click(field);
        await typist.paste(text);
    }

    it('imports the balances: details, file, mapping and review', async () => {
        const { api, typist } = await startWizard();

        await fillDetails(typist, '2026-04-01');
        await provide(typist, `${csv}9999,5.00,0\n9998,0,5.00\n`);

        expect(
            await screen.findByText('4 baris saldo terbaca.'),
        ).toBeInTheDocument();
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        // Codes that equal an account of the company are mapped; the other two wait for a choice.
        expect(
            await screen.findByText('2 kode belum dipetakan.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Lanjut' })).toBeDisabled();

        for (const code of ['9999', '9998']) {
            await typist.click(
                screen.getByRole('combobox', { name: `Akun untuk ${code}` }),
            );
            await typist.click(
                await screen.findByRole('option', {
                    name: code === '9999' ? '1121 · Piutang' : '3110 · Modal',
                }),
            );
        }

        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(await screen.findByText('Total debit')).toBeInTheDocument();
        await typist.click(
            screen.getByRole('button', { name: 'Impor saldo konversi' }),
        );

        await waitFor(() => expect(api.called(post)).toHaveLength(1));
        expect(api.called(post)[0]?.body).toEqual({
            source_key: 'legacy-2026-04',
            source_system: null,
            cutover_date: '2026-06-01',
            ledger_start_date: '2026-04-01',
            account_mapping: [
                { source_code: '1111', account_code: '1111' },
                { source_code: '3110', account_code: '3110' },
                { source_code: '9999', account_code: '1121' },
                { source_code: '9998', account_code: '3110' },
            ],
            lines: [
                {
                    source_account_code: '1111',
                    debit: '1000000.00',
                    credit: '0',
                    description: null,
                },
                {
                    source_account_code: '3110',
                    debit: '0',
                    credit: '1000000.00',
                    description: null,
                },
                {
                    source_account_code: '9999',
                    debit: '5.00',
                    credit: '0',
                    description: null,
                },
                {
                    source_account_code: '9998',
                    debit: '0',
                    credit: '5.00',
                    description: null,
                },
            ],
        });

        // The page now shows what was imported, with the journal.
        expect(
            await screen.findByRole('heading', { name: 'Saldo konversi' }),
        ).toBeInTheDocument();
        expect(
            await screen.findByRole('link', { name: 'JU-2026-04-0001' }),
        ).toBeInTheDocument();
    });

    it('uses the cutover as the start when no history start is given', async () => {
        const { api, typist } = await startWizard();

        await fillDetails(typist);
        await provide(typist, csv);
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Impor saldo konversi' }),
        );

        await waitFor(() => expect(api.called(post)).toHaveLength(1));
        expect(api.called(post)[0]?.body).toMatchObject({
            cutover_date: '2026-06-01',
            ledger_start_date: '2026-06-01',
        });
    });

    it('will not go on without a key and a date, and names the unreadable rows', async () => {
        const { typist } = await startWizard();

        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        expect(await screen.findAllByText('Wajib diisi')).toHaveLength(2);

        await fillDetails(typist);
        await provide(typist, 'source_code,debit,credit\n1111,abc,0\n');

        expect(
            await screen.findByText(/Baris 2: "abc" bukan angka/),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Lanjut' })).toBeDisabled();
    });

    it('will not import balances that do not balance', async () => {
        const { api, typist } = await startWizard();

        await fillDetails(typist);
        await provide(
            typist,
            'source_code,debit,credit\n1111,100.00,0\n3110,0,90.00\n',
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );

        expect(
            await screen.findByText(/Total debit dan kredit berbeda/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Impor saldo konversi' }),
        ).toBeDisabled();
        expect(api.called(post)).toHaveLength(0);
    });

    it('shows what the ledger refused', async () => {
        const api = mockApi({
            ...routes(manage),
            [`GET ${base}/opening-balance-imports`]: notFound,
            [post]: () =>
                json(
                    {
                        message: 'Kode sumber belum dipetakan.',
                        code: 'MAPPING_REQUIRED',
                        errors: {
                            'lines.0.source_account_code': [
                                'Akun sumber 1111 belum dipetakan.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await fillDetails(typist);
        await provide(typist, csv);
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Impor saldo konversi' }),
        );

        expect(
            await screen.findByText('Kode sumber belum dipetakan.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Akun sumber 1111 belum dipetakan.'),
        ).toBeInTheDocument();
        expect(api.called(post)).toHaveLength(1);
    });

    it('offers no wizard to someone who may not import', async () => {
        mockApi({
            ...routes(['journals.read']),
            [`GET ${base}/opening-balance-imports`]: notFound,
        });

        renderApp(path);

        expect(
            await screen.findByText('Saldo konversi belum diimpor.'),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText('Kunci sumber')).not.toBeInTheDocument();
    });
});

describe('history import wizard (GL-FR-11)', () => {
    const post = `POST ${base}/history-imports`;

    it('shows the imported opening balances and says when there is no history to import', async () => {
        mockApi({
            ...routes(manage),
            [`GET ${base}/opening-balance-imports`]: () =>
                json({ data: batch({ cutover_date: '2026-04-01' }) }),
        });

        renderApp(path);

        expect(
            await screen.findByRole('link', { name: 'JU-2026-04-0001' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/tidak ada histori untuk diimpor/),
        ).toBeInTheDocument();
    });

    it('imports the history and the cutover trial balance', async () => {
        const api = mockApi({
            ...routes(manage),
            [`GET ${base}/opening-balance-imports`]: () =>
                json({ data: batch() }),
            [`GET ${base}/history-imports`]: notFound,
            [post]: () =>
                json(
                    {
                        data: {
                            id: '01JHISTORY',
                            batch_id: '01JBATCH',
                            source_key: 'legacy-history',
                            granularity: 'period_summary',
                            journal_count: 1,
                            created_at: '2026-10-03T10:00:00Z',
                        },
                    },
                    201,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        expect(
            await screen.findByText(
                /Histori bertanggal 2026-04-01 sampai 2026-05-31/,
            ),
        ).toBeInTheDocument();
        await typist.type(
            screen.getByLabelText('Kunci sumber'),
            'legacy-history',
        );
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        const journals = await screen.findByRole('textbox', {
            name: /Jurnal histori/,
        });

        await typist.click(journals);
        await typist.paste(
            'date,journal,source_code,debit,credit\n2026-04-30,APR,1111,500.00,0\n2026-04-30,APR,3110,0,500.00\n',
        );
        expect(
            await screen.findByText('1 jurnal terbaca.'),
        ).toBeInTheDocument();
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        const balances = await screen.findByRole('textbox', {
            name: /Neraca saldo sumber pada cutover/,
        });

        await typist.click(balances);
        await typist.paste(
            'source_code,debit,credit\n1111,1500000.00,0\n3110,0,1500000.00\n',
        );
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        // Mapped by code; nothing left to choose.
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );
        await typist.click(
            await screen.findByRole('button', { name: 'Impor histori' }),
        );

        await waitFor(() => expect(api.called(post)).toHaveLength(1));
        expect(api.called(post)[0]?.body).toMatchObject({
            source_key: 'legacy-history',
            granularity: 'period_summary',
            journals: [
                {
                    accounting_date: '2026-04-30',
                    description: 'APR',
                    lines: [
                        {
                            source_account_code: '1111',
                            debit: '500.00',
                            credit: '0',
                        },
                        {
                            source_account_code: '3110',
                            debit: '0',
                            credit: '500.00',
                        },
                    ],
                },
            ],
            cutover_balances: [
                {
                    source_account_code: '1111',
                    debit: '1500000.00',
                    credit: '0',
                },
                {
                    source_account_code: '3110',
                    debit: '0',
                    credit: '1500000.00',
                },
            ],
        });
    });

    it('keeps history journals inside the history and balanced', async () => {
        mockApi({
            ...routes(manage),
            [`GET ${base}/opening-balance-imports`]: () =>
                json({ data: batch() }),
            [`GET ${base}/history-imports`]: notFound,
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Kunci sumber'), 'k');
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        const journals = await screen.findByRole('textbox', {
            name: /Jurnal histori/,
        });

        await typist.click(journals);
        await typist.paste(
            'date,journal,source_code,debit,credit\n2026-06-01,A,1111,5.00,0\n2026-06-01,A,3110,0,4.00\n',
        );

        expect(
            await screen.findByText(/2026-06-01.*TTTT-BB-HH|harus berbentuk/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('1 jurnal tidak seimbang.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Lanjut' })).toBeDisabled();
    });

    it('shows the history that was imported', async () => {
        mockApi({
            ...routes(manage),
            [`GET ${base}/opening-balance-imports`]: () =>
                json({ data: batch({ history_granularity: 'transactions' }) }),
            [`GET ${base}/history-imports`]: () =>
                json({
                    data: {
                        id: '01JHISTORY',
                        batch_id: '01JBATCH',
                        source_key: 'legacy-history',
                        granularity: 'transactions',
                        journal_count: 42,
                        created_at: '2026-10-03T10:00:00Z',
                    },
                }),
        });

        renderApp(path);

        const section = (
            await screen.findByRole('heading', { name: 'Histori' })
        ).closest('section') as HTMLElement;

        expect(
            await within(section).findByText('legacy-history'),
        ).toBeInTheDocument();
        expect(within(section).getByText('Per transaksi')).toBeInTheDocument();
        expect(within(section).getByText('42')).toBeInTheDocument();
    });
});

describe('opening open items (SL-FR-10, SL-AC-01)', () => {
    const post = `POST ${base}/opening-balance-imports`;
    const control = accountRow(
        '01JACCOUNT0000000000000009',
        '1130',
        'Piutang usaha',
        { allowed_sources: ['subledger'] },
    );
    const partyId = '01JPARTY00000000000000001';
    const party = {
        id: partyId,
        name: 'PT Maju Jaya',
        is_customer: true,
        is_supplier: false,
        tax_id: null,
        posting_profile_id: null,
        status: 'active',
        external_refs: [{ source_system: 'legacy', external_ref: 'C-001' }],
        lock_version: 1,
    };
    const profile = {
        id: '01JPROFILE00000000000000001',
        name: 'Default',
        is_default: true,
        lock_version: 1,
        accounts: [
            {
                key: 'receivables_control',
                account_id: control.id,
                effective_date: '2026-01-01',
                valid: true,
                lock_version: 1,
            },
            {
                key: 'payables_control',
                account_id: null,
                effective_date: null,
                valid: false,
                lock_version: 0,
            },
        ],
    };
    const balances =
        'source_code,debit,credit\n1130,1500000.00,0\n3110,0,1500000.00\n';
    const items = (amount: string) =>
        `control_role,party,kind,document_number,document_date,amount\nreceivables,C-001,invoice,INV-0001,2026-03-15,${amount}\n`;

    it('reads open items and nets them per control role', () => {
        const { items: read, problems } = parseOpenItems(
            [
                'control_role,party,kind,document_number,document_date,due_date,amount',
                'receivables,C-001,invoice,INV-1,2026-03-15,2026-04-14,1500.00',
                'receivables,C-001,credit_note,CN-1,2026-03-20,,500.00',
                'payables,S-001,invoice,B-1,2026-03-01,,300.00',
                'other,S-001,invoice,B-2,2026-03-01,,300.00',
            ].join('\n'),
        );

        expect(problems).toEqual([{ line: 5, kind: 'role', detail: 'other' }]);
        expect(read).toHaveLength(3);
        // An invoice raises receivables on the debit side and payables on the credit side.
        expect(openItemTotals(read)).toEqual({
            receivables_control: '1000.00',
            payables_control: '-300.00',
        });
    });

    async function toReview(csvItems: string) {
        let imported = false;
        const api = mockApi({
            ...withPermissions([
                ...manage,
                'parties.read',
                'posting-profiles.read',
            ]),
            [`GET ${base}/accounts`]: () => list([control, capital]),
            [`GET ${base}/parties`]: () => list([party]),
            [`GET ${base}/posting-profiles`]: () => list([profile]),
            [`GET ${base}/opening-balance-imports`]: () =>
                imported ? json({ data: batch() }) : notFound(),
            [`GET ${base}/history-imports`]: () => notFound(),
            [post]: () => {
                imported = true;

                return json({ data: batch() }, 201);
            },
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Kunci sumber'),
            'legacy-2026-04',
        );
        await typist.type(screen.getByLabelText('Sistem sumber'), 'legacy');
        await pickDate(
            typist,
            screen.getByRole('button', { name: /Tanggal cutover/ }),
            '2026-06-01',
        );
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        await typist.click(
            await screen.findByRole('textbox', { name: /Neraca saldo awal/ }),
        );
        await typist.paste(balances);
        await typist.click(
            screen.getByRole('textbox', { name: /Open item awal/ }),
        );
        await typist.paste(csvItems);
        expect(
            await screen.findByText('1 open item terbaca.'),
        ).toBeInTheDocument();
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        // The codes equal accounts of the company, so only the parties are left to map.
        await typist.click(
            await screen.findByRole('button', { name: 'Lanjut' }),
        );

        return { api, typist };
    }

    it('maps the source parties and sends the open items with the batch', async () => {
        const { api, typist } = await toReview(items('1500000.00'));

        // The reference the source system already has on the party is suggested.
        expect(
            await screen.findByRole('combobox', { name: 'Pihak untuk C-001' }),
        ).toHaveValue('PT Maju Jaya');
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        expect(await screen.findByText('Open item')).toBeInTheDocument();
        await typist.click(
            screen.getByRole('button', { name: 'Impor saldo konversi' }),
        );

        await waitFor(() => expect(api.called(post)).toHaveLength(1));
        expect(api.called(post)[0]?.body).toMatchObject({
            open_items: [
                {
                    party_id: partyId,
                    control_role: 'receivables_control',
                    kind: 'invoice',
                    document_number: 'INV-0001',
                    document_date: '2026-03-15',
                    due_date: null,
                    currency: null,
                    amount: '1500000.00',
                    exchange_rate: null,
                },
            ],
        });
    });

    it('stops at the review when the open items differ from the control balance', async () => {
        const { api, typist } = await toReview(items('1000000.00'));

        await screen.findByRole('combobox', { name: 'Pihak untuk C-001' });
        await typist.click(screen.getByRole('button', { name: 'Lanjut' }));

        expect(
            await screen.findByText(/berbeda dari total open item/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Impor saldo konversi' }),
        ).toBeDisabled();
        expect(api.called(post)).toHaveLength(0);
    });
});
