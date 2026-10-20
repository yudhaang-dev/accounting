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
const classId = '01JCLASS000000000000000001';
const cashPath = `/w/${workspace.company_id}/reports/classifications/${classId}/cash-flow`;
const equityPath = `/w/${workspace.company_id}/reports/classifications/${classId}/equity-changes`;
const listPath = `/w/${workspace.company_id}/reports`;

const classification = {
    id: classId,
    key: 'standard',
    name: 'Klasifikasi Standar',
    current_version: 2,
    lock_version: 1,
    version: 2,
    structure: { rows: [], rules: [] },
};

const coverage = {
    coverage_from: '2026-01-01',
    coverage_to: '2026-10-07',
    coverage_granularity: 'transactions',
    coverage_status: 'complete',
};

const routes = (result: () => Response, kind: 'cash-flow' | 'equity-changes') => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({
            data: [
                {
                    ...workspace,
                    permissions: ['reports.read', 'accounts.read'],
                },
            ],
        }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/movement-classifications/${classId}`]: () =>
        json({ data: classification }),
    [`GET ${base}/movement-classifications/${classId}/${kind}`]: result,
    [`GET ${base}/accounts`]: () =>
        json({
            data: [
                {
                    id: 'a-cash',
                    code: '1111',
                    name: 'Kas Kecil',
                    class: 'asset',
                    is_leaf: true,
                    status: 'active',
                },
            ],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 1 },
        }),
});

const cashFlow = (extra: Record<string, unknown> = {}) => ({
    classification: { id: classId, key: 'standard', version: 2 },
    parameters: { from: '2026-01-01', to: '2026-10-07' },
    functional_currency: 'IDR',
    restricted: false,
    rows: [
        {
            key: 'operating',
            label: 'Operasional',
            inflow: '500.00',
            outflow: '200.00',
            net: '300.00',
        },
    ],
    unclassified: [],
    mapping: { version: 1, account_ids: ['a-cash'], problems: {} },
    cash: {
        opening_balance: '100.00',
        imported_opening_balance: '0.00',
        net_increase: '300.00',
        closing_balance: '400.00',
        difference: '0.00',
        reconciled: true,
    },
    ...coverage,
    ...extra,
});

describe('direct cash flow', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows inflow, outflow and net per row and the cash reconciliation (RP-AC-02)', async () => {
        mockApi(routes(() => json({ data: cashFlow() }), 'cash-flow'));

        renderApp(cashPath);

        const row = (await screen.findByText('Operasional')).closest(
            'tr',
        ) as HTMLElement;

        expect(within(row).getByText('500,00')).toBeInTheDocument();
        expect(within(row).getByText('200,00')).toBeInTheDocument();
        expect(within(row).getByText('300,00')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Kas awal ditambah kenaikan bersih sama dengan kas akhir',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Klasifikasi Standar · versi 2')).toBeVisible();
    });

    it('lists unclassified movements and a cash difference', async () => {
        mockApi(
            routes(
                () =>
                    json({
                        data: cashFlow({
                            unclassified: [
                                {
                                    account_id: 'a1',
                                    code: '5100',
                                    name: 'Beban Gaji',
                                    transaction_type_key: 'payroll',
                                },
                            ],
                            cash: {
                                opening_balance: '100.00',
                                imported_opening_balance: '0.00',
                                net_increase: '300.00',
                                closing_balance: '450.00',
                                difference: '50.00',
                                reconciled: false,
                            },
                        }),
                    }),
                'cash-flow',
            ),
        );

        renderApp(cashPath);

        expect(
            await screen.findByText('Mutasi yang belum diklasifikasikan'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Beban Gaji/)).toHaveTextContent('payroll');
        // The account opens its ledger over the dates of the report (X-FR-02 drill-down).
        const link = screen.getByRole('link', {
            name: 'Lihat buku besar akun 5100',
        });

        expect(link.getAttribute('href')).toMatch(
            /\/reports\/general-ledger\?account_id=a1&from=\d{4}-01-01&to=/,
        );
        expect(
            screen.getByText('Arus kas tidak cocok dengan saldo kas'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Selisih 50,00/)).toBeInTheDocument();
    });

    it('names the accounts that cannot be cash equivalents', async () => {
        mockApi(
            routes(
                () =>
                    json({
                        data: cashFlow({
                            mapping: {
                                version: 1,
                                account_ids: ['a-cash'],
                                problems: { 'a-cash': ['Akun bukan aset.'] },
                            },
                        }),
                    }),
                'cash-flow',
            ),
        );

        renderApp(cashPath);

        expect(
            await screen.findByText(
                'Pemetaan kas dan setara kas bermasalah',
            ),
        ).toBeInTheDocument();
        expect(
            await screen.findByText(/1111 Kas Kecil: Akun bukan aset\./),
        ).toBeInTheDocument();
    });

    it('shows the API message when cash_equivalents is not mapped', async () => {
        mockApi(
            routes(
                () =>
                    json(
                        {
                            message:
                                'Peran cash_equivalents belum dipetakan.',
                            code: 'VALIDATION_FAILED',
                            errors: {
                                cash_equivalents: [
                                    'Peran cash_equivalents belum dipetakan.',
                                ],
                            },
                        },
                        422,
                    ),
                'cash-flow',
            ),
        );

        renderApp(cashPath);

        expect(
            await screen.findByText('Peran cash_equivalents belum dipetakan.'),
        ).toBeInTheDocument();
    });
});

const equity = (extra: Record<string, unknown> = {}) => ({
    classification: { id: classId, key: 'standard', version: 2 },
    parameters: { from: '2026-01-01', to: '2026-10-07' },
    functional_currency: 'IDR',
    restricted: false,
    components: [
        {
            key: 'capital',
            label: 'Modal',
            opening_balance: '1000.00',
            movement: '100.00',
            closing_balance: '1100.00',
            reconciled: true,
        },
        {
            key: 'retained',
            label: 'Saldo laba',
            opening_balance: '50.00',
            movement: '-20.00',
            closing_balance: '30.00',
            reconciled: true,
        },
    ],
    rows: [
        {
            key: 'profit',
            label: 'Laba tahun berjalan',
            values: { capital: '0.00', retained: '-20.00' },
            total: '-20.00',
        },
    ],
    unclassified: [],
    equity: {
        components_total: '1130.00',
        ledger_total: '1130.00',
        difference: '0.00',
        reconciled: true,
        balanced: true,
    },
    ...coverage,
    ...extra,
});

describe('changes in equity', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows opening, movement and closing per component and per row (RP-AC-09)', async () => {
        mockApi(routes(() => json({ data: equity() }), 'equity-changes'));

        renderApp(equityPath);

        const capital = (await screen.findAllByText('Modal'))[0]
            ?.closest('tr') as HTMLElement;

        expect(within(capital).getByText('1.000,00')).toBeInTheDocument();
        expect(within(capital).getByText('1.100,00')).toBeInTheDocument();

        const profit = screen
            .getByText('Laba tahun berjalan')
            .closest('tr') as HTMLElement;

        expect(within(profit).getAllByText('-20,00')).toHaveLength(2);
        expect(
            screen.getByText('Total komponen sama dengan ekuitas di buku besar'),
        ).toBeInTheDocument();
    });

    it('flags a component and the ledger that do not match', async () => {
        mockApi(
            routes(
                () =>
                    json({
                        data: equity({
                            equity: {
                                components_total: '1130.00',
                                ledger_total: '1100.00',
                                difference: '30.00',
                                reconciled: false,
                                balanced: true,
                            },
                        }),
                    }),
                'equity-changes',
            ),
        );

        renderApp(equityPath);

        expect(
            await screen.findByText(
                'Perubahan ekuitas tidak cocok dengan buku besar',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText(/selisih 30,00/)).toBeInTheDocument();
    });
});

describe('classification links', () => {
    afterEach(() => vi.restoreAllMocks());

    it('lists the classifications on the reports screen with both reports', async () => {
        mockApi({
            ...routes(() => json({ data: equity() }), 'equity-changes'),
            [`GET ${base}/report-definitions`]: () =>
                json({
                    data: [],
                    links: {},
                    meta: { current_page: 1, last_page: 1, total: 0 },
                }),
            [`GET ${base}/movement-classifications`]: () =>
                json({
                    data: [classification],
                    links: {},
                    meta: { current_page: 1, last_page: 1, total: 1 },
                }),
        });

        renderApp(listPath);

        expect(
            await screen.findByRole('link', { name: 'Arus kas langsung' }),
        ).toHaveAttribute('href', cashPath);
        expect(
            screen.getByRole('link', { name: 'Perubahan ekuitas' }),
        ).toHaveAttribute('href', equityPath);
    });
});

describe('classification report export (X-FR-02)', () => {
    afterEach(() => vi.restoreAllMocks());

    it.each([
        ['cash-flow', cashPath, cashFlow],
        ["equity-changes", equityPath, equity],
    ] as const)(
        'exports the %s report as CSV with the same filters',
        async (kind, path, body) => {
            let downloaded: string | undefined;

            URL.createObjectURL = vi.fn(() => 'blob:csv');
            URL.revokeObjectURL = vi.fn();
            vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
                function (this: HTMLAnchorElement) {
                    downloaded = this.download;
                },
            );

            const api = mockApi({
                ...routes(() => json({ data: body() }), kind),
                [`GET ${base}/movement-classifications/${classId}/${kind}`]: (
                    call,
                ) =>
                    call.accept?.includes('text/csv')
                        ? new Response('row,label\n', {
                              headers: {
                                  'Content-Type': 'text/csv; charset=UTF-8',
                              },
                          })
                        : json({ data: body() }),
            });

            renderApp(path);
            const typist = userEvent.setup();

            await screen.findByText('Klasifikasi Standar · versi 2');
            await typist.click(
                screen.getByRole('button', { name: 'Ekspor CSV' }),
            );

            await waitFor(() => expect(downloaded).toBeDefined());
            expect(downloaded).toMatch(
                new RegExp(`^${kind}_standard_\\d{4}-01-01_`),
            );
            expect(
                api
                    .called(
                        `GET ${base}/movement-classifications/${classId}/${kind}`,
                    )
                    .some((call) => call.accept?.includes('text/csv')),
            ).toBe(true);
        },
    );
});
