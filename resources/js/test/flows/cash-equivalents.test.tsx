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
const path = `/w/${workspace.company_id}/reports/cash-equivalents`;
const mappingUrl = `${base}/reporting/account-role-mappings`;

const acc = (id: string, code: string, name: string, extra = {}) => ({
    id,
    code,
    name,
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: true,
    status: 'active',
    lock_version: 1,
    ...extra,
});

const accounts = [
    acc('a-cash', '1111', 'Kas Kecil'),
    acc('a-bank', '1120', 'Bank BCA'),
    acc('a-old', '1130', 'Bank Lama', { status: 'inactive' }),
    acc('a-sales', '4100', 'Penjualan', { class: 'income' }),
];

const routes = (
    mapping: Record<string, unknown>,
    permissions = ['reports.read', 'report-definitions.manage'],
) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/accounts`]: () =>
        json({
            data: accounts,
            links: {},
            meta: { current_page: 1, last_page: 1, total: accounts.length },
        }),
    [`GET ${mappingUrl}`]: () =>
        json({
            data: [
                {
                    key: 'cash_equivalents',
                    account_ids: [],
                    effective_date: null,
                    problems: {},
                    lock_version: 0,
                    ...mapping,
                },
            ],
        }),
});

describe('cash equivalents mapping', () => {
    afterEach(() => vi.restoreAllMocks());

    it('says the role is not mapped and offers only active asset leaves', async () => {
        mockApi(routes({}));

        renderApp(path);

        expect(await screen.findByText('Belum dipetakan')).toBeInTheDocument();

        const group = screen.getByRole('group', {
            name: 'Akun kas dan setara kas',
        });

        expect(within(group).getByText('1111 · Kas Kecil')).toBeInTheDocument();
        expect(within(group).getByText('1120 · Bank BCA')).toBeInTheDocument();
        expect(within(group).queryByText(/Bank Lama/)).toBeNull();
        expect(within(group).queryByText(/Penjualan/)).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        ).toBeDisabled();
    });

    it('saves the picked accounts as a new version with the version that was read', async () => {
        const api = mockApi({
            ...routes({}),
            [`PUT ${mappingUrl}/cash_equivalents`]: () =>
                json({
                    data: {
                        key: 'cash_equivalents',
                        account_ids: ['a-cash', 'a-bank'],
                        effective_date: '2026-10-07',
                        lock_version: 1,
                    },
                }),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', { name: '1111 · Kas Kecil' }),
        );
        await typist.click(
            screen.getByRole('checkbox', { name: '1120 · Bank BCA' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        );

        await waitFor(() =>
            expect(
                api.called(`PUT ${mappingUrl}/cash_equivalents`),
            ).toHaveLength(1),
        );
        expect(
            api.called(`PUT ${mappingUrl}/cash_equivalents`)[0]?.body,
        ).toEqual({
            lock_version: 0,
            account_ids: ['a-cash', 'a-bank'],
            effective_date: null,
        });
    });

    it('shows the accounts that no longer qualify and keeps them removable', async () => {
        mockApi(
            routes({
                account_ids: ['a-old'],
                lock_version: 2,
                effective_date: '2026-01-01',
                problems: { 'a-old': ['Akun tidak aktif.'] },
            }),
        );

        renderApp(path);

        expect(
            await screen.findByText('Akun yang tidak lagi memenuhi syarat'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/1130 · Bank Lama: Akun tidak aktif\./),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', { name: '1130 · Bank Lama' }),
        ).toBeChecked();
        expect(screen.getByText(/Versi 2, 1 akun\./)).toBeInTheDocument();
    });

    it('reloads on a version conflict instead of overwriting', async () => {
        let reads = 0;
        const api = mockApi({
            ...routes({}),
            [`GET ${mappingUrl}`]: () => {
                reads += 1;

                return json({
                    data: [
                        {
                            key: 'cash_equivalents',
                            account_ids: reads > 1 ? ['a-bank'] : [],
                            effective_date: null,
                            problems: {},
                            lock_version: reads > 1 ? 3 : 0,
                        },
                    ],
                });
            },
            [`PUT ${mappingUrl}/cash_equivalents`]: () =>
                json(
                    { message: 'Konflik versi', code: 'VERSION_CONFLICT' },
                    409,
                ),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', { name: '1111 · Kas Kecil' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        );

        await waitFor(() =>
            expect(
                screen.getByRole('checkbox', { name: '1120 · Bank BCA' }),
            ).toBeChecked(),
        );
        expect(api.called(`GET ${mappingUrl}`).length).toBeGreaterThan(1);
    });

    it('is read-only without report-definitions.manage', async () => {
        mockApi(routes({}, ['reports.read']));

        renderApp(path);

        expect(
            await screen.findByRole('checkbox', { name: '1111 · Kas Kecil' }),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.queryByRole('button', { name: 'Simpan pemetaan' }),
        ).toBeNull();
    });
});
