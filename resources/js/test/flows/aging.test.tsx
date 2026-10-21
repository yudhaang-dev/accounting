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

const itemId = '01JITEM000000000000000001';

const report = () => ({
    parameters: {
        as_of: '2026-10-06',
        control_role: 'receivables_control',
        party_id: null,
        basis: 'due_date',
        buckets: [
            { key: 'current', from_days: null, to_days: 0 },
            { key: '1-30', from_days: 1, to_days: 30 },
            { key: 'over_30', from_days: 31, to_days: null },
        ],
    },
    functional_currency: 'IDR',
    totals: {
        current: '0.000000',
        '1-30': '1000000.000000',
        over_30: '0.000000',
        total: '1000000.000000',
    },
    currency_totals: [
        {
            currency: 'IDR',
            buckets: { current: '0', '1-30': '1000000', over_30: '0' },
            total: '1000000.000000',
        },
    ],
    parties: [
        {
            party_id: '01JPARTY00000000000000001',
            party_name: 'PT Maju Jaya',
            totals: {
                current: '0.000000',
                '1-30': '1000000.000000',
                over_30: '0.000000',
                total: '1000000.000000',
            },
            items: [
                {
                    open_item_id: itemId,
                    document_id: '01JDOC00000000000000000001',
                    document_number: 'INV-001',
                    kind: 'invoice',
                    document_date: '2026-09-01',
                    due_date: '2026-09-20',
                    currency: 'IDR',
                    remaining_amount: '1000000.000000',
                    remaining_functional: '1000000.000000',
                    age_days: 16,
                    bucket: '1-30',
                },
            ],
        },
    ],
});

const routes = (permissions: string[]) => ({
    ...withPermissions(permissions),
    [`GET ${base}/reports/aging`]: () => json({ data: report() }),
    [`GET ${base}/parties`]: () => list([]),
    [`GET ${base}/aging-policy`]: () =>
        json({
            data: { basis: 'due_date', buckets: [30, 60, 90], lock_version: 0 },
        }),
});

describe('aging (SL-FR-08, SL-AC-04)', () => {
    it('shows buckets per party and document with a drill-down to the item', async () => {
        const api = mockApi(routes(['reports.read', 'allocations.read']));

        renderApp(`${home}/reports/aging`);

        expect(await screen.findByText('PT Maju Jaya')).toBeInTheDocument();
        expect(screen.getAllByText('1–30 hari').length).toBeGreaterThan(0);
        expect(
            screen.getAllByText('Lebih dari 30 hari').length,
        ).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'INV-001' })).toHaveAttribute(
            'href',
            `${home}/allocations?open_item=${itemId}`,
        );
        expect(
            api.called(`GET ${base}/reports/aging`)[0]?.query.control_role,
        ).toBe('receivables_control');
    });

    it('switches to payables', async () => {
        const index = `GET ${base}/reports/aging`;
        const api = mockApi(routes(['reports.read']));

        renderApp(`${home}/reports/aging`);
        const typist = userEvent.setup();

        await screen.findByText('PT Maju Jaya');
        await typist.click(screen.getByRole('button', { name: 'Utang' }));

        await waitFor(() =>
            expect(api.called(index).at(-1)?.query.control_role).toBe(
                'payables_control',
            ),
        );
    });

    it('edits the buckets and refuses a descending list without calling the API', async () => {
        const update = `PUT ${base}/aging-policy`;
        const api = mockApi({
            ...routes(['reports.read', 'aging-policy.manage']),
            [update]: () =>
                json({
                    data: {
                        basis: 'due_date',
                        buckets: [15, 45],
                        lock_version: 1,
                    },
                }),
        });

        renderApp(`${home}/reports/aging`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Bucket' }),
        );
        const dialog = await screen.findByRole('dialog');
        const input = await within(dialog).findByLabelText(
            'Batas bucket (hari)',
        );

        await typist.clear(input);
        await typist.type(input, '60, 30');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );
        expect(api.called(update)).toHaveLength(0);
        expect(within(dialog).getByText(/menaik/)).toBeInTheDocument();

        await typist.clear(input);
        await typist.type(input, '15, 45');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() => expect(api.called(update)).toHaveLength(1));
        expect(api.called(update)[0]?.body).toEqual({
            basis: 'due_date',
            buckets: [15, 45],
            lock_version: 0,
        });
    });

    it('offers no bucket editor without the policy permission', async () => {
        mockApi(routes(['reports.read']));

        renderApp(`${home}/reports/aging`);

        await screen.findByText('PT Maju Jaya');
        expect(screen.queryByRole('button', { name: 'Bucket' })).toBeNull();
    });
});
