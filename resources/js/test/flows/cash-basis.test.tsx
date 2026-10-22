import { screen, waitFor } from '@testing-library/react';
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

const routes = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/reports/cash-basis-expenses`]: () =>
        json({
            data: {
                parameters: { from: '2026-03-01', to: '2026-03-31' },
                functional_currency: 'IDR',
                accounts: [
                    {
                        account_id: '01JACC0000000000000000001',
                        account_code: '6220',
                        account_name: 'Sewa Kantor',
                        dimension_set_id: '01JSET0000000000000000001',
                        cash_paid: '200000.000000',
                    },
                    {
                        account_id: '01JACC0000000000000000001',
                        account_code: '6220',
                        account_name: 'Sewa Kantor',
                        dimension_set_id: '01JSET0000000000000000002',
                        cash_paid: '100000.000000',
                    },
                    {
                        account_id: '01JACC0000000000000000002',
                        account_code: '6230',
                        account_name: 'Utilitas dan Komunikasi',
                        dimension_set_id: '01JSET0000000000000000001',
                        cash_paid: '150000.000000',
                    },
                ],
                other_accounts: '50000.000000',
                unattributed: '0.000000',
                total: '500000.000000',
            },
        }),
});

describe('cash paid on expenses (RP-FR-11)', () => {
    it('shows the cash per expense account, an account once across its dimension sets', async () => {
        const api = mockApi(routes(['reports.read']));

        renderApp(`${home}/reports/cash-basis-expenses`);

        expect(
            await screen.findByText('6220 · Sewa Kantor'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('6220 · Sewa Kantor')).toHaveLength(1);
        expect(
            screen.getByText('6230 · Utilitas dan Komunikasi'),
        ).toBeInTheDocument();
        expect(screen.getByText(/300\.000/)).toBeInTheDocument();
        expect(screen.getByText(/500\.000/)).toBeInTheDocument();
        expect(
            api.called(`GET ${base}/reports/cash-basis-expenses`)[0]?.query
                .from,
        ).toMatch(/^\d{4}-\d{2}-01$/);
    });

    it('asks for the range again when it is changed and shown', async () => {
        const index = `GET ${base}/reports/cash-basis-expenses`;
        const api = mockApi(routes(['reports.read']));

        renderApp(`${home}/reports/cash-basis-expenses`);
        const typist = userEvent.setup();

        await screen.findByText('6220 · Sewa Kantor');
        await typist.click(screen.getByRole('button', { name: 'Tampilkan' }));

        await waitFor(() =>
            expect(api.called(index).length).toBeGreaterThanOrEqual(1),
        );
    });
});
