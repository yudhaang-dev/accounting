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
const newPath = `/w/${workspace.company_id}/reports/classifications/new`;
const editPath = `/w/${workspace.company_id}/reports/classifications/${classId}/edit`;
const listPath = `/w/${workspace.company_id}/reports`;
const url = `${base}/movement-classifications`;

const page = (data: unknown[]) => ({
    data,
    links: {},
    meta: { current_page: 1, last_page: 1, total: data.length },
});

const classification = {
    id: classId,
    key: 'standard',
    name: 'Klasifikasi Standar',
    current_version: 2,
    lock_version: 5,
    version: 2,
    structure: {
        rows: [
            { key: 'operating', label: 'Operasional' },
            { key: 'investing', label: 'Investasi' },
        ],
        rules: [
            {
                row: 'investing',
                accounts: [{ type: 'class', class: 'asset' }],
                transaction_type_keys: ['capex', 'asset_sale'],
            },
            {
                row: 'operating',
                accounts: [{ type: 'class', class: 'income' }],
                transaction_type_keys: [],
            },
        ],
        components: [
            {
                key: 'capital',
                label: 'Modal',
                accounts: [{ type: 'class', class: 'equity' }],
            },
        ],
    },
};

const routes = (permissions = ['reports.read', 'report-definitions.manage']) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
    [`GET ${base}/accounts`]: () => json(page([])),
    [`GET ${base}/report-definitions`]: () => json(page([])),
    [`GET ${url}`]: () => json(page([classification])),
    [`GET ${url}/${classId}`]: () => json({ data: classification }),
});

describe('movement classification editor', () => {
    afterEach(() => vi.restoreAllMocks());

    it('creates a classification from rows and rules', async () => {
        const api = mockApi({
            ...routes(),
            [`GET ${url}`]: () => json(page([classification])),
            [`POST ${url}`]: () => json({ data: classification }, 201),
        });

        renderApp(newPath);
        const typist = userEvent.setup();

        await typist.type(await screen.findByLabelText('Kunci'), 'standard');
        await typist.type(screen.getByLabelText('Nama'), 'Klasifikasi Standar');
        await typist.type(screen.getByLabelText('Kunci baris 1'), 'operating');
        await typist.type(screen.getByLabelText('Label baris 1'), 'Operasional');
        await typist.click(
            screen.getByRole('combobox', { name: 'Baris tujuan aturan 1' }),
        );
        await typist.click(
            await screen.findByRole('option', { name: 'operating' }),
        );
        await typist.type(
            screen.getByLabelText('Key Jenis Transaksi aturan 1'),
            'sales, payroll ,',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Buat klasifikasi' }),
        );

        await waitFor(() => expect(api.called(`POST ${url}`)).toHaveLength(1));
        expect(api.called(`POST ${url}`)[0]?.body).toEqual({
            key: 'standard',
            name: 'Klasifikasi Standar',
            structure: {
                rows: [{ key: 'operating', label: 'Operasional' }],
                rules: [
                    {
                        row: 'operating',
                        accounts: [{ type: 'class', class: 'asset' }],
                        transaction_type_keys: ['sales', 'payroll'],
                    },
                ],
            },
        });
    });

    it('loads rows, rules and components and saves with the lock version', async () => {
        const api = mockApi({
            ...routes(),
            [`PUT ${url}/${classId}`]: () => json({ data: classification }),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        expect(
            await screen.findByText(/Versi saat ini: 2/),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Kunci')).toBeDisabled();
        expect(screen.getByLabelText('Key Jenis Transaksi aturan 1')).toHaveValue(
            'capex, asset_sale',
        );
        expect(screen.getByLabelText('Label komponen 1')).toHaveValue('Modal');

        await typist.clear(screen.getByLabelText('Label komponen 1'));
        await typist.type(screen.getByLabelText('Label komponen 1'), 'Modal disetor');
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() =>
            expect(api.called(`PUT ${url}/${classId}`)).toHaveLength(1),
        );

        const body = api.called(`PUT ${url}/${classId}`)[0]?.body as {
            lock_version: number;
            structure: {
                rules: { row: string; transaction_type_keys: string[] }[];
                components: { label: string }[];
            };
        };

        expect(body.lock_version).toBe(5);
        expect(body.structure.components[0]?.label).toBe('Modal disetor');
        expect(body.structure.rules[0]).toMatchObject({
            row: 'investing',
            transaction_type_keys: ['capex', 'asset_sale'],
        });
    });

    it('keeps the order of the rules because the first match wins', async () => {
        const api = mockApi({
            ...routes(),
            [`PUT ${url}/${classId}`]: () => json({ data: classification }),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        await screen.findByText(/Versi saat ini: 2/);
        await typist.click(
            screen.getByRole('button', { name: 'Turunkan aturan 1' }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() =>
            expect(api.called(`PUT ${url}/${classId}`)).toHaveLength(1),
        );

        const body = api.called(`PUT ${url}/${classId}`)[0]?.body as {
            structure: { rules: { row: string }[] };
        };

        expect(body.structure.rules.map((rule) => rule.row)).toEqual([
            'operating',
            'investing',
        ]);
    });

    it('leaves the components out when the classification does not use them', async () => {
        const api = mockApi({
            ...routes(),
            [`PUT ${url}/${classId}`]: () => json({ data: classification }),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', {
                name: 'Klasifikasi ini dipakai untuk perubahan ekuitas',
            }),
        );
        await typist.click(
            screen.getByRole('button', { name: 'Simpan perubahan' }),
        );

        await waitFor(() =>
            expect(api.called(`PUT ${url}/${classId}`)).toHaveLength(1),
        );
        expect(
            (api.called(`PUT ${url}/${classId}`)[0]?.body as { structure: object })
                .structure,
        ).not.toHaveProperty('components');
    });

    it('puts the server messages on the rule or component they belong to', async () => {
        mockApi({
            ...routes(),
            [`PUT ${url}/${classId}`]: () =>
                json(
                    {
                        message: 'The given data was invalid.',
                        code: 'VALIDATION_FAILED',
                        errors: {
                            'structure.rules.1.row': [
                                'Baris tujuan tidak dikenal.',
                            ],
                            'structure.rules.0.accounts.0.id': [
                                'Akun tidak ditemukan.',
                            ],
                            'structure.components.0.key': [
                                'Kunci komponen tidak valid.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp(editPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Simpan perubahan' }),
        );

        expect(
            await screen.findByText('Baris tujuan tidak dikenal.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Akun tidak ditemukan.')).toBeInTheDocument();
        expect(
            screen.getByText('Kunci komponen tidak valid.'),
        ).toBeInTheDocument();
    });

    it('offers the editor from the list to managers only', async () => {
        mockApi(routes());

        renderApp(listPath);

        expect(
            await screen.findByRole('link', { name: 'Klasifikasi baru' }),
        ).toHaveAttribute('href', `${listPath}/classifications/new`);
        expect(
            screen.getByRole('link', { name: 'Ubah klasifikasi Klasifikasi Standar' }),
        ).toHaveAttribute('href', editPath);
    });

    it('hides the edit links without report-definitions.manage', async () => {
        mockApi(routes(['reports.read']));

        renderApp(listPath);

        const row = (await screen.findByText('Klasifikasi Standar')).closest(
            'tr',
        ) as HTMLElement;

        expect(within(row).queryByRole('link', { name: /Ubah/ })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Klasifikasi baru' })).toBeNull();
    });
});
