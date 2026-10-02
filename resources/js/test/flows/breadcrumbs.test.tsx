import { screen, within } from '@testing-library/react';
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
const entryId = '01JENTRY00000000000000001';
const journalId = '01JJOURNAL0000000000000001';

const read = [
    'company.read',
    'accounts.read',
    'periods.read',
    'entries.read',
    'entries.create',
    'journals.read',
    'reports.read',
];

const routes = {
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions: read }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/accounts`]: () =>
        json({
            data: [],
            links: {},
            meta: { current_page: 1, last_page: 1, total: 0 },
        }),
    [`GET ${base}/transaction-types`]: () => json({ data: [] }),
    [`GET ${base}/entries/${entryId}`]: () =>
        json({
            data: {
                id: entryId,
                number: 'ENT/2026/10/000001',
                status: 'draft',
                source: 'manual',
                transaction_type_key: 'general',
                transaction_type_version: 1,
                accounting_date: '2026-10-02',
                document_date: null,
                description: 'Tes',
                lines: [],
                created_by: 1,
                journal_id: null,
                journal_number: null,
                posted_at: null,
                decisions: [],
                lock_version: 1,
            },
        }),
    [`GET ${base}/journals/${journalId}`]: () =>
        json({
            data: {
                id: journalId,
                number: 'JU/2026/10/000001',
                accounting_date: '2026-10-02',
                period_id: 'p',
                document_date: null,
                description: 'Tes',
                source: 'entry',
                transaction_type_key: 'general',
                transaction_type_version: 1,
                references: null,
                reverses_journal_id: null,
                entry_id: null,
                posted_by: 1,
                posted_at: '2026-10-02T09:00:00+00:00',
                lines: [],
            },
        }),
    [`GET ${base}/reports/trial-balance`]: () =>
        json({
            data: {
                parameters: {
                    from: '2026-01-01',
                    to: '2026-10-02',
                    account_id: null,
                },
                functional_currency: 'IDR',
                balanced: true,
                totals: {
                    opening_debit: '0.00',
                    opening_credit: '0.00',
                    debit: '0.00',
                    credit: '0.00',
                    closing_debit: '0.00',
                    closing_credit: '0.00',
                },
                rows: [],
            },
        }),
};

const trail = async () =>
    within(await screen.findByRole('navigation', { name: 'Navigasi halaman' }));

describe('breadcrumbs', () => {
    it('names the company alone on its home, as the page you are on', async () => {
        mockApi(routes);

        renderApp(home);

        const crumbs = await trail();

        expect(crumbs.getByText('Toko Budi')).toHaveAttribute(
            'aria-current',
            'page',
        );
        // The current page is shown as a disabled link by the component; none can be followed.
        expect(
            screen
                .getByRole('navigation', { name: 'Navigasi halaman' })
                .querySelector('a'),
        ).toBeNull();
    });

    it('leads from the company to a screen, with the company as a link back', async () => {
        mockApi(routes);

        renderApp(`${home}/accounts`);

        const crumbs = await trail();

        expect(crumbs.getByRole('link', { name: 'Toko Budi' })).toHaveAttribute(
            'href',
            home,
        );
        expect(crumbs.getByText('Akun')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('shows the number of the entry being read, under the list it belongs to', async () => {
        mockApi(routes);

        renderApp(`${home}/entries/${entryId}`);

        const crumbs = await trail();

        expect(crumbs.getByRole('link', { name: 'Entri' })).toHaveAttribute(
            'href',
            `${home}/entries`,
        );
        expect(await crumbs.findByText('ENT/2026/10/000001')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('goes one step further when the entry is being edited', async () => {
        mockApi(routes);

        renderApp(`${home}/entries/${entryId}/edit`);

        const crumbs = await trail();

        expect(crumbs.getByRole('link', { name: 'Entri' })).toBeInTheDocument();
        expect(
            await crumbs.findByRole('link', { name: 'ENT/2026/10/000001' }),
        ).toHaveAttribute('href', `${home}/entries/${entryId}`);
        expect(crumbs.getByText('Ubah')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('shows a new entry as a step under the entries', async () => {
        mockApi(routes);

        renderApp(`${home}/entries/new`);

        const crumbs = await trail();

        expect(crumbs.getByRole('link', { name: 'Entri' })).toBeInTheDocument();
        expect(crumbs.getByText('Baru')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('names the journal by its number', async () => {
        mockApi(routes);

        renderApp(`${home}/journals/${journalId}`);

        const crumbs = await trail();

        expect(crumbs.getByRole('link', { name: 'Jurnal' })).toHaveAttribute(
            'href',
            `${home}/journals`,
        );
        expect(await crumbs.findByText('JU/2026/10/000001')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('groups reports without making the group a link', async () => {
        mockApi(routes);

        renderApp(`${home}/reports/trial-balance`);

        const crumbs = await trail();

        expect(crumbs.getByText('Laporan')).not.toHaveAttribute('aria-current');
        expect(
            crumbs.queryByRole('link', { name: 'Laporan' }),
        ).not.toBeInTheDocument();
        expect(crumbs.getByText('Neraca saldo')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('moves within the app when a step is clicked', async () => {
        mockApi(routes);

        const { router } = renderApp(`${home}/entries/${entryId}`);
        const typist = userEvent.setup();

        await typist.click(
            (await trail()).getByRole('link', { name: 'Entri' }),
        );

        expect(router.state.location.pathname).toBe(`${home}/entries`);
    });

    it('starts at the workspaces for screens outside a company', async () => {
        mockApi({
            ...routes,
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
        });

        renderApp('/workspaces/new');

        const crumbs = await trail();

        expect(
            crumbs.getByRole('link', { name: 'Ruang kerja' }),
        ).toHaveAttribute('href', '/workspaces');
        expect(crumbs.getByText('Baru')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(crumbs.queryByText('Toko Budi')).not.toBeInTheDocument();
    });

    it('replaces the back buttons of the detail screens', async () => {
        mockApi(routes);

        renderApp(`${home}/entries/${entryId}`);
        await trail();

        expect(
            screen.queryByRole('button', { name: /Ke daftar entri/ }),
        ).not.toBeInTheDocument();
    });
});
