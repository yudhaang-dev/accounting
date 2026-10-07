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
const listPath = `/w/${workspace.company_id}/reports`;
const templates = `${base}/report-definition-templates`;

const page = (data: unknown[]) => ({
    data,
    links: {},
    meta: { current_page: 1, last_page: 1, total: data.length },
});

const acc = (id: string, code: string, name: string) => ({
    id,
    code,
    name,
    class: 'asset',
    normal_balance: 'debit',
    parent_id: null,
    is_leaf: false,
    status: 'active',
    lock_version: 1,
});

const template = (extraCode = '9999') => ({
    key: 'standard',
    name: 'Standar Indonesia',
    version: 1,
    definitions: [
        {
            key: 'income_statement',
            name: 'Laba Rugi',
            kind: 'income_statement',
            structure: {
                basis: 'movement',
                rows: [
                    {
                        key: 'revenue',
                        label: 'Pendapatan',
                        type: 'accounts',
                        accounts: [{ class: 'income' }],
                    },
                    {
                        key: 'total',
                        label: 'Laba bersih',
                        type: 'subtotal',
                    },
                ],
                columns: [{ key: 'now', label: 'Tahun ini' }],
            },
        },
        {
            key: 'balance_sheet',
            name: 'Neraca',
            kind: 'balance_sheet',
            structure: {
                basis: 'closing',
                rows: [
                    {
                        key: 'assets',
                        label: 'Aset',
                        type: 'accounts',
                        accounts: [{ code: '1000' }, { code: extraCode }],
                    },
                ],
                columns: [{ key: 'now', label: 'Per tanggal' }],
            },
        },
    ],
});

const routes = (
    options: {
        permissions?: string[];
        existing?: string[];
        extraCode?: string;
    } = {},
) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({
            data: [
                {
                    ...workspace,
                    permissions: options.permissions ?? [
                        'reports.read',
                        'report-definitions.manage',
                        'accounts.read',
                    ],
                },
            ],
        }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/accounts`]: () =>
        json(page([acc('a1', '1000', 'Aset Lancar'), acc('a2', '1200', 'Piutang')])),
    [`GET ${base}/report-definitions`]: () =>
        json(
            page(
                (options.existing ?? []).map((key) => ({
                    id: `id-${key}`,
                    key,
                    name: key,
                    kind: 'custom',
                    current_version: 1,
                    lock_version: 1,
                    version: 1,
                    structure: {},
                })),
            ),
        ),
    [`GET ${base}/movement-classifications`]: () => json(page([])),
    [`GET ${templates}`]: () =>
        json({
            data: [
                {
                    key: 'standard',
                    name: 'Standar Indonesia',
                    version: 1,
                    definition_count: 2,
                },
            ],
        }),
    [`GET ${templates}/standard`]: () =>
        json({ data: template(options.extraCode) }),
});

const open = async (typist: ReturnType<typeof userEvent.setup>) => {
    await typist.click(
        await screen.findByRole('button', { name: 'Impor template' }),
    );

    return screen.findByRole('dialog');
};

describe('report template import', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows each definition with its rows and the accounts it names, then imports the confirmed ones (X-AC-03)', async () => {
        const api = mockApi({
            ...routes({ extraCode: '1200' }),
            [`POST ${base}/report-definitions/import-template`]: () =>
                json(
                    { data: { template: 'standard', created: [] } },
                    201,
                ),
        });

        renderApp(listPath);
        const typist = userEvent.setup();
        const dialog = await open(typist);

        expect(await within(dialog).findByText('Laba Rugi')).toBeInTheDocument();
        expect(within(dialog).getByText('Pendapatan', { selector: 'li' })).toHaveTextContent(
            'Pendapatan · Pendapatan',
        );
        expect(within(dialog).getByText('Aset', { selector: 'li' })).toHaveTextContent(
            '1000 Aset Lancar, 1200 Piutang',
        );

        const submit = within(dialog).getByRole('button', {
            name: 'Impor 2 definisi',
        });

        expect(submit).toBeDisabled();

        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: /Saya sudah membaca/,
            }),
        );
        await typist.click(submit);

        await waitFor(() =>
            expect(
                api.called(`POST ${base}/report-definitions/import-template`),
            ).toHaveLength(1),
        );
        expect(
            api.called(`POST ${base}/report-definitions/import-template`)[0]
                ?.body,
        ).toEqual({
            template: 'standard',
            keys: ['income_statement', 'balance_sheet'],
            confirmed: true,
        });
    });

    it('names the template accounts the chart lacks and blocks the import', async () => {
        mockApi(routes({ extraCode: '9999' }));

        renderApp(listPath);
        const typist = userEvent.setup();
        const dialog = await open(typist);

        const alert = await within(dialog).findByRole('alert');

        expect(
            within(alert).getByText('Akun template tidak ada di bagan akun'),
        ).toBeInTheDocument();
        expect(within(alert).getByText('Neraca: akun 9999')).toBeInTheDocument();
        expect(
            within(dialog).getByText('1 akun tidak ada'),
        ).toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: /Saya sudah membaca/,
            }),
        );

        expect(
            within(dialog).getByRole('button', { name: 'Impor 2 definisi' }),
        ).toBeDisabled();

        // Leaving the definition out removes the problem.
        await typist.click(
            within(dialog).getByRole('checkbox', { name: 'Neraca' }),
        );

        expect(within(dialog).queryByRole('alert')).toBeNull();
        expect(
            within(dialog).getByRole('button', { name: 'Impor 1 definisi' }),
        ).toBeEnabled();
    });

    it('leaves out a definition whose key the company already uses', async () => {
        mockApi(routes({ existing: ['income_statement'], extraCode: '1200' }));

        renderApp(listPath);
        const typist = userEvent.setup();
        const dialog = await open(typist);

        expect(await within(dialog).findByText('Sudah ada')).toBeInTheDocument();
        expect(
            within(dialog).getByRole('checkbox', { name: 'Laba Rugi' }),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            within(dialog).getByRole('button', { name: 'Impor 1 definisi' }),
        ).toBeInTheDocument();
    });

    it('shows what the server refused', async () => {
        mockApi({
            ...routes({ extraCode: '1200' }),
            [`POST ${base}/report-definitions/import-template`]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            keys: ['Kunci income_statement sudah dipakai.'],
                        },
                    },
                    422,
                ),
        });

        renderApp(listPath);
        const typist = userEvent.setup();
        const dialog = await open(typist);

        await within(dialog).findByText('Laba Rugi');
        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: /Saya sudah membaca/,
            }),
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Impor 2 definisi' }),
        );

        expect(
            await within(dialog).findByText(
                'Kunci income_statement sudah dipakai.',
            ),
        ).toBeInTheDocument();
    });

    it('says the chart cannot be checked without accounts.read, and does not block', async () => {
        mockApi(
            routes({
                permissions: ['reports.read', 'report-definitions.manage'],
                extraCode: '9999',
            }),
        );

        renderApp(listPath);
        const typist = userEvent.setup();
        const dialog = await open(typist);

        expect(
            await within(dialog).findByText(/Anda tidak bisa membaca bagan akun/),
        ).toBeInTheDocument();

        await typist.click(
            within(dialog).getByRole('checkbox', {
                name: /Saya sudah membaca/,
            }),
        );

        expect(
            within(dialog).getByRole('button', { name: 'Impor 2 definisi' }),
        ).toBeEnabled();
    });

    it('offers the import only to managers', async () => {
        mockApi(routes({ permissions: ['reports.read'] }));

        renderApp(listPath);

        await screen.findByText('Belum ada definisi laporan');
        expect(
            screen.queryByRole('button', { name: 'Impor template' }),
        ).toBeNull();
    });
});
