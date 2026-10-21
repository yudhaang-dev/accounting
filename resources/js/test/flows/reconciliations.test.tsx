import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { rowAction } from '@/test/actions';
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

const differenceId = '01JRECON0000000000000001';
const journalId = '01JJOURNAL000000000000001';

const reconciliation = (overrides: Record<string, unknown> = {}) => ({
    id: differenceId,
    kind: 'control',
    account_id: '01JACCOUNT00000000000001',
    account_code: '1-1300',
    currency: 'IDR',
    as_of: '2026-12-31',
    ledger_balance: '1500000.00',
    detail_total: '1000000.00',
    difference: '500000.00',
    status: 'difference',
    causes: [
        {
            journal_id: journalId,
            number: 'JV-2026-0001',
            accounting_date: '2026-12-15',
            source: 'entry',
            debit: '500000.00',
            credit: '0.00',
            description: 'Penyesuaian manual',
        },
    ],
    waiver_reason: null,
    waived_by: null,
    waived_at: null,
    run_by: 1,
    created_at: '2026-12-31T10:00:00+00:00',
    ...overrides,
});

const balanced = reconciliation({
    id: '01JRECON0000000000000002',
    kind: 'cash',
    account_code: '1-1100',
    ledger_balance: '200000.00',
    detail_total: '200000.00',
    difference: '0.00',
    status: 'balanced',
    causes: [],
});

const routes = (
    permissions: string[],
    rows = [reconciliation(), balanced],
) => ({
    ...withPermissions(permissions),
    [`GET ${base}/reconciliations`]: () => list(rows),
});

const manage = [
    'reconciliations.read',
    'reconciliations.run',
    'reconciliations.waive',
    'journals.read',
];

describe('reconciliation (SL-FR-09, SL-AC-05)', () => {
    it('lists differences with their status and offers causes only for unbalanced rows', async () => {
        mockApi(routes(manage));

        renderApp(`${home}/reconciliations`);

        expect(await screen.findByText('1-1300')).toBeInTheDocument();
        expect(screen.getByText('1-1100')).toBeInTheDocument();
        expect(screen.getByText('Selisih', { selector: 'span' })).toBeVisible();
        expect(screen.getByText('Sesuai')).toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: /Aksi untuk/ }),
        ).toHaveLength(1);
    });

    it('traces a difference to the journals behind it', async () => {
        mockApi(routes(manage));

        renderApp(`${home}/reconciliations`);
        const typist = userEvent.setup();

        await rowAction(typist, '1-1300', 'Lihat penyebab');
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog).getByRole('link', { name: 'JV-2026-0001' }),
        ).toHaveAttribute('href', `${home}/journals/${journalId}`);
        expect(
            within(dialog).getByText('Penyesuaian manual'),
        ).toBeInTheDocument();
    });

    it('runs the reconciliation for a date and lists its result', async () => {
        const run = `POST ${base}/reconciliations`;
        const index = `GET ${base}/reconciliations`;
        const api = mockApi({
            ...routes(manage, []),
            [run]: () => json({ data: [reconciliation()] }, 201),
        });

        renderApp(`${home}/reconciliations`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Jalankan rekonsiliasi',
            }),
        );

        await waitFor(() => expect(api.called(run)).toHaveLength(1));
        expect(api.called(run)[0]?.body).toEqual({
            as_of: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
        });
        // The list is filtered to the date that ran.
        await waitFor(() =>
            expect(api.called(index).at(-1)?.query['filter[as_of]']).toMatch(
                /^\d{4}-\d{2}-\d{2}$/,
            ),
        );
    });

    it('waives a difference with a reason', async () => {
        const waive = `POST ${base}/reconciliations/${differenceId}/waive`;
        const api = mockApi({
            ...routes(manage),
            [waive]: () =>
                json({
                    data: reconciliation({
                        status: 'waived',
                        waiver_reason: 'Selisih kurs dimaklumi',
                    }),
                }),
        });

        renderApp(`${home}/reconciliations`);
        const typist = userEvent.setup();

        await rowAction(typist, '1-1300', 'Waiver selisih');
        const dialog = await screen.findByRole('dialog');

        // A reason is required.
        await typist.click(
            within(dialog).getByRole('button', { name: 'Waiver selisih' }),
        );
        expect(api.called(waive)).toHaveLength(0);

        await typist.type(
            within(dialog).getByLabelText('Alasan'),
            'Selisih kurs dimaklumi',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Waiver selisih' }),
        );

        await waitFor(() => expect(api.called(waive)).toHaveLength(1));
        expect(api.called(waive)[0]?.body).toEqual({
            reason: 'Selisih kurs dimaklumi',
        });
    });

    it('opens on the date linked from the year closing checklist', async () => {
        const index = `GET ${base}/reconciliations`;
        const api = mockApi(routes(manage));

        renderApp(`${home}/reconciliations?as_of=2026-12-31`);

        expect(await screen.findByText('Per 2026-12-31')).toBeInTheDocument();
        expect(api.called(index)[0]?.query['filter[as_of]']).toBe('2026-12-31');
    });

    it('is read-only without the run and waive permissions', async () => {
        mockApi(routes(['reconciliations.read']));

        renderApp(`${home}/reconciliations`);

        await screen.findByText('1-1300');
        expect(
            screen.queryByRole('button', { name: 'Jalankan rekonsiliasi' }),
        ).toBeNull();

        const typist = userEvent.setup();
        await typist.click(
            screen.getByRole('button', { name: 'Aksi untuk 1-1300' }),
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Lihat penyebab' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Waiver selisih' }),
        ).toBeNull();
    });
});
