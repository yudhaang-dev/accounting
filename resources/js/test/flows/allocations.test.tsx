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
const invoiceItem = '01JITEM000000000000000001';
const advanceItem = '01JITEM000000000000000002';
const otherCurrency = '01JITEM000000000000000003';
const allocationId = '01JALLOC0000000000000001';

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

const item = (overrides: Record<string, unknown>) => ({
    id: invoiceItem,
    document_id: '01JDOC00000000000000000001',
    party_id: partyId,
    control_role: 'receivables_control',
    kind: 'invoice',
    side: 'debit',
    document_number: 'INV-001',
    document_date: '2026-09-01',
    due_date: '2026-09-30',
    currency: 'IDR',
    original_amount: '1000000.00',
    remaining_amount: '1000000.00',
    original_functional: '1000000.00',
    remaining_functional: '1000000.00',
    exchange_rate: '1.000000',
    status: 'open',
    ...overrides,
});

const items = [
    item({}),
    item({
        id: advanceItem,
        kind: 'advance',
        side: 'credit',
        document_number: 'PAY-001',
        original_amount: '600000.00',
        remaining_amount: '600000.00',
    }),
    item({
        id: otherCurrency,
        kind: 'advance',
        side: 'credit',
        document_number: 'PAY-USD',
        currency: 'USD',
    }),
];

const allocation = (overrides: Record<string, unknown> = {}) => ({
    id: allocationId,
    debit_item_id: invoiceItem,
    credit_item_id: advanceItem,
    document_id: null,
    amount: '600000.00',
    functional_amount: '600000.00',
    credit_functional_amount: '600000.00',
    realized_difference: '0.000000',
    journal_id: null,
    cancel_journal_id: null,
    allocation_date: '2026-10-01',
    status: 'active',
    created_by: 1,
    cancelled_on: null,
    cancelled_by: null,
    cancel_reason: null,
    ...overrides,
});

const routes = (permissions: string[], rows = [allocation()]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/allocations`]: () => list(rows),
    [`GET ${base}/open-items`]: () => list(items),
    [`GET ${base}/parties`]: () => list([party]),
});

const manage = [
    'allocations.read',
    'allocations.manage',
    'open-items.read',
    'parties.read',
    'journals.read',
];

describe('allocations (SL-FR-05)', () => {
    it('lists allocations naming both items, with cancelled ones and their reason', async () => {
        mockApi(
            routes(manage, [
                allocation(),
                allocation({
                    id: '01JALLOC0000000000000002',
                    status: 'cancelled',
                    cancelled_on: '2026-10-02',
                    cancel_reason: 'Salah pihak',
                    realized_difference: '500000.000000',
                    journal_id: '01JJOURNAL000000000000001',
                }),
            ]),
        );

        renderApp(`${home}/allocations`);

        expect((await screen.findAllByText('INV-001')).length).toBe(2);
        expect(screen.getAllByText('PAY-001')).toHaveLength(2);
        expect(screen.getByText('Salah pihak')).toBeInTheDocument();
        expect(screen.getByText(/Dibatalkan 2026-10-02/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Buka jurnal' }),
        ).toHaveAttribute('href', `${home}/journals/01JJOURNAL000000000000001`);
        // Only the active allocation can be cancelled.
        expect(
            screen.getAllByRole('button', { name: /Aksi untuk/ }),
        ).toHaveLength(1);
    });

    it('filters by the open item of the link from the open items list', async () => {
        const index = `GET ${base}/allocations`;
        const api = mockApi(routes(manage));

        renderApp(`${home}/allocations?open_item=${invoiceItem}`);

        expect(
            await screen.findByText('Open item INV-001'),
        ).toBeInTheDocument();
        expect(api.called(index)[0]?.query['filter[open_item_id]']).toBe(
            invoiceItem,
        );
    });

    it('offers only items of the same currency as the debit item and caps the amount', async () => {
        const create = `POST ${base}/allocations`;
        const api = mockApi({
            ...routes(manage),
            [create]: () => json({ data: allocation() }, 201),
        });

        renderApp(`${home}/allocations`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Alokasi baru' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(within(dialog).getByLabelText('Pihak'));
        await typist.click(
            await screen.findByRole('option', { name: 'PT Maju Jaya' }),
        );
        await typist.click(within(dialog).getByLabelText('Item debit'));
        await typist.click(
            await screen.findByRole('option', { name: /INV-001/ }),
        );
        await typist.click(within(dialog).getByLabelText('Item kredit'));

        expect(
            await screen.findByRole('option', { name: /PAY-001/ }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /PAY-USD/ })).toBeNull();

        await typist.click(screen.getByRole('option', { name: /PAY-001/ }));

        // The amount starts at the smaller remainder.
        expect(within(dialog).getByLabelText('Jumlah')).toHaveValue(
            '600.000,00',
        );

        await typist.click(
            within(dialog).getByRole('button', { name: 'Alokasikan' }),
        );

        await waitFor(() => expect(api.called(create)).toHaveLength(1));
        expect(api.called(create)[0]?.body).toEqual({
            open_item_id: invoiceItem,
            counterpart_open_item_id: advanceItem,
            amount: '600000.00',
            allocation_date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
        });
    });

    it('refuses an amount above the smaller remainder without calling the API', async () => {
        const create = `POST ${base}/allocations`;
        const api = mockApi(routes(manage));

        renderApp(`${home}/allocations`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Alokasi baru' }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.click(within(dialog).getByLabelText('Pihak'));
        await typist.click(
            await screen.findByRole('option', { name: 'PT Maju Jaya' }),
        );
        await typist.click(within(dialog).getByLabelText('Item debit'));
        await typist.click(
            await screen.findByRole('option', { name: /INV-001/ }),
        );
        await typist.click(within(dialog).getByLabelText('Item kredit'));
        await typist.click(
            await screen.findByRole('option', { name: /PAY-001/ }),
        );

        const amount = within(dialog).getByLabelText('Jumlah');
        await typist.clear(amount);
        await typist.type(amount, '700000');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Alokasikan' }),
        );

        expect(
            await within(dialog).findByText(/sisa terkecil/),
        ).toBeInTheDocument();
        expect(api.called(create)).toHaveLength(0);
    });

    it('cancels an allocation with a reason', async () => {
        const cancel = `POST ${base}/allocations/${allocationId}/cancel`;
        const api = mockApi({
            ...routes(manage),
            [cancel]: () => json({ data: allocation({ status: 'cancelled' }) }),
        });

        renderApp(`${home}/allocations`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: /Aksi untuk/ }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Batalkan alokasi' }),
        );
        const dialog = await screen.findByRole('dialog');

        // A reason is required.
        await typist.click(
            within(dialog).getByRole('button', { name: 'Batalkan alokasi' }),
        );
        expect(api.called(cancel)).toHaveLength(0);

        await typist.type(
            within(dialog).getByLabelText('Alasan'),
            'Salah pihak',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Batalkan alokasi' }),
        );

        await waitFor(() => expect(api.called(cancel)).toHaveLength(1));
        expect(api.called(cancel)[0]?.body).toEqual({ reason: 'Salah pihak' });
    });

    it('is read-only without the manage permission', async () => {
        mockApi(routes(['allocations.read']));

        renderApp(`${home}/allocations`);

        await screen.findByText('2026-10-01');
        expect(
            screen.queryByRole('button', { name: 'Alokasi baru' }),
        ).toBeNull();
        expect(screen.queryByRole('button', { name: /Aksi untuk/ })).toBeNull();
    });
});
