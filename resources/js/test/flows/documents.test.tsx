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

type Routes = Parameters<typeof mockApi>[0];

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

const partyId = '01JPARTY00000000000000001';
const documentId = '01JDOC00000000000000000001';
const journalId = '01JJOURNAL000000000000001';
const entryId = '01JENTRY00000000000000001';

const party = {
    id: partyId,
    name: 'PT Maju Jaya',
    roles: ['customer'],
    tax_id: null,
    posting_profile_id: null,
    status: 'active',
    external_refs: [],
    lock_version: 1,
};

const document = (overrides: Record<string, unknown> = {}) => ({
    id: documentId,
    kind: 'invoice',
    control_role: 'receivables_control',
    party_id: partyId,
    cash_account_id: null,
    journal_id: journalId,
    entry_id: entryId,
    document_number: 'INV-001',
    document_date: '2026-09-01',
    due_date: '2026-09-30',
    accounting_date: '2026-09-01',
    currency: 'IDR',
    amount: '1500000.00',
    functional_amount: '1500000.00',
    exchange_rate: '1.000000',
    description: 'Penjualan September',
    is_opening: false,
    reversal_journal_id: null,
    reversal_date: null,
    ...overrides,
});

const openItem = (overrides: Record<string, unknown> = {}) => ({
    id: '01JITEM000000000000000001',
    document_id: documentId,
    party_id: partyId,
    control_role: 'receivables_control',
    kind: 'invoice',
    side: 'debit',
    document_number: 'INV-001',
    document_date: '2026-09-01',
    due_date: '2026-09-30',
    currency: 'IDR',
    original_amount: '1500000.00',
    remaining_amount: '500000.00',
    original_functional: '1500000.00',
    remaining_functional: '500000.00',
    exchange_rate: '1.000000',
    status: 'open',
    ...overrides,
});

const all = [
    'documents.read',
    'open-items.read',
    'parties.read',
    'journals.read',
    'entries.read',
];

describe('documents (SL-FR-04)', () => {
    it('lists documents with their party, kind, amount, and opening and reversed marks', async () => {
        mockApi({
            ...withPermissions(all),
            [`GET ${base}/documents`]: () =>
                list([
                    document(),
                    document({
                        id: '01JDOC00000000000000000002',
                        document_number: 'INV-002',
                        is_opening: true,
                    }),
                    document({
                        id: '01JDOC00000000000000000003',
                        document_number: 'INV-003',
                        reversal_journal_id: '01JJOURNAL000000000000009',
                        reversal_date: '2026-09-15',
                    }),
                ]),
            [`GET ${base}/parties`]: () => list([party]),
        });

        renderApp(`${home}/documents`);

        expect(await screen.findByText('INV-001')).toBeInTheDocument();
        expect((await screen.findAllByText('PT Maju Jaya')).length).toBe(3);
        expect(screen.getAllByText(/1\.500\.000,00/).length).toBeGreaterThan(0);
        expect(screen.getByText('Saldo awal')).toBeInTheDocument();
        expect(screen.getByText('Dibalik')).toBeInTheDocument();
    });

    it('opens a document with links to its journal and entry', async () => {
        mockApi({
            ...withPermissions(all),
            [`GET ${base}/documents`]: () => list([document()]),
            [`GET ${base}/parties`]: () => list([party]),
        });

        renderApp(`${home}/documents`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Buka dokumen INV-001' }),
        );
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog).getByText('Penjualan September'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: 'Buka jurnal' }),
        ).toHaveAttribute('href', `${home}/journals/${journalId}`);
        expect(
            within(dialog).getByRole('link', { name: 'Buka entri' }),
        ).toHaveAttribute('href', `${home}/entries/${entryId}`);
    });

    describe('reversal (SL-FR-12)', () => {
        const reverse = `POST ${base}/journals/${journalId}/reverse`;
        const routes = (overrides: Routes = {}): Routes => ({
            ...withPermissions([...all, 'entries.reverse']),
            [`GET ${base}/documents`]: () => list([document()]),
            [`GET ${base}/parties`]: () => list([party]),
            ...overrides,
        });

        const openReversal = async (
            typist: ReturnType<typeof userEvent.setup>,
        ) => {
            await typist.click(
                await screen.findByRole('button', {
                    name: 'Buka dokumen INV-001',
                }),
            );
            const detail = await screen.findByRole('dialog');

            await typist.click(
                within(detail).getByRole('button', { name: 'Balik dokumen' }),
            );

            const dialogs = await screen.findAllByRole('dialog');
            const dialog = dialogs.at(-1) as HTMLElement;

            await typist.type(within(dialog).getByLabelText('Alasan'), 'Salah');
            await typist.click(
                within(dialog).getByRole('button', {
                    name: 'Buat draf pembalikan',
                }),
            );

            return dialog;
        };

        it('asks for the reversal of the journal of the document', async () => {
            const api = mockApi(
                routes({
                    [reverse]: () =>
                        json({ data: { id: entryId, number: 'PB-1' } }, 201),
                }),
            );

            renderApp(`${home}/documents`);
            await openReversal(userEvent.setup());

            await waitFor(() => expect(api.called(reverse)).toHaveLength(1));
            expect(api.called(reverse)[0]?.body).toMatchObject({
                reason: 'Salah',
            });
        });

        it('says which active allocation stops the reversal', async () => {
            mockApi(
                routes({
                    [reverse]: () =>
                        json(
                            {
                                message: 'Tidak dapat dibalik.',
                                code: 'INVALID_STATE_TRANSITION',
                                errors: {
                                    journal_id: [
                                        'Dokumen INV-001 masih memiliki alokasi aktif; batalkan alokasinya sebelum membalik dokumen.',
                                    ],
                                },
                            },
                            409,
                        ),
                }),
            );

            renderApp(`${home}/documents`);
            const dialog = await openReversal(userEvent.setup());

            expect(
                await within(dialog).findByText(/masih memiliki alokasi aktif/),
            ).toBeInTheDocument();
        });

        it('offers no reversal without the permission', async () => {
            mockApi({
                ...withPermissions(all),
                [`GET ${base}/documents`]: () => list([document()]),
                [`GET ${base}/parties`]: () => list([party]),
            });

            renderApp(`${home}/documents`);

            await userEvent.setup().click(
                await screen.findByRole('button', {
                    name: 'Buka dokumen INV-001',
                }),
            );
            await screen.findByRole('dialog');

            expect(
                screen.queryByRole('button', { name: 'Balik dokumen' }),
            ).toBeNull();
        });

        it('offers no reversal for a document that is reversed already', async () => {
            mockApi(
                routes({
                    [`GET ${base}/documents`]: () =>
                        list([
                            document({
                                reversal_journal_id:
                                    '01JJOURNAL000000000000009',
                                reversal_date: '2026-09-15',
                            }),
                        ]),
                }),
            );

            renderApp(`${home}/documents`);

            await userEvent.setup().click(
                await screen.findByRole('button', {
                    name: 'Buka dokumen INV-001',
                }),
            );
            await screen.findByRole('dialog');

            expect(
                screen.queryByRole('button', { name: 'Balik dokumen' }),
            ).toBeNull();
        });
    });

    it('sends the filters to the API', async () => {
        const index = `GET ${base}/documents`;
        const api = mockApi({
            ...withPermissions(all),
            [index]: () => list([document()]),
            [`GET ${base}/parties`]: () => list([party]),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/documents`);
        await screen.findByText('INV-001');

        await typist.click(screen.getByRole('combobox', { name: 'Jenis' }));
        await typist.click(
            await screen.findByRole('option', { name: 'Pembayaran' }),
        );

        await waitFor(() =>
            expect(api.called(index).at(-1)?.query['filter[kind]']).toBe(
                'payment',
            ),
        );
    });

    it('works without party and journal permissions', async () => {
        mockApi({
            ...withPermissions(['documents.read']),
            [`GET ${base}/documents`]: () => list([document()]),
        });

        renderApp(`${home}/documents`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Buka dokumen INV-001' }),
        );
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).queryByRole('link')).toBeNull();
        expect(screen.queryByText('PT Maju Jaya')).toBeNull();
    });
});

describe('open items (SL-FR-04)', () => {
    it('lists open items with original and remaining amounts, open by default', async () => {
        const index = `GET ${base}/open-items`;
        const api = mockApi({
            ...withPermissions(all),
            [index]: () =>
                list([
                    openItem(),
                    openItem({
                        id: '01JITEM000000000000000002',
                        document_number: 'INV-002',
                        status: 'reversed',
                    }),
                ]),
            [`GET ${base}/parties`]: () => list([party]),
        });

        renderApp(`${home}/open-items`);

        expect(await screen.findByText('INV-001')).toBeInTheDocument();
        expect(screen.getAllByText('PT Maju Jaya')).toHaveLength(2);
        expect(screen.getAllByText(/500\.000,00/).length).toBeGreaterThan(0);
        expect(screen.getByText('Dibalik')).toBeInTheDocument();
        expect(api.called(index)[0]?.query['filter[status]']).toBe('open');
    });

    it('shows every status when the status filter is cleared', async () => {
        const index = `GET ${base}/open-items`;
        const api = mockApi({
            ...withPermissions(all),
            [index]: () => list([openItem()]),
            [`GET ${base}/parties`]: () => list([party]),
        });
        const typist = userEvent.setup();

        renderApp(`${home}/open-items`);
        await screen.findByText('INV-001');

        await typist.click(screen.getByRole('combobox', { name: 'Status' }));
        await typist.click(
            await screen.findByRole('option', { name: 'Semua status' }),
        );

        await waitFor(() =>
            expect(
                api.called(index).at(-1)?.query['filter[status]'],
            ).toBeUndefined(),
        );
    });
});
