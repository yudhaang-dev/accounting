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

// Where a new entry starts: the catalog of transaction types, then the form of the one chosen (TE-FR-01).

const base = `/api/v1/companies/${workspace.company_id}`;
const entriesPath = `/w/${workspace.company_id}/entries`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const type = (
    key: string,
    label: string,
    description: string | null,
    status: 'published' | 'draft' = 'published',
) => ({
    id: `01JTYPE-${key}`,
    key,
    label,
    description,
    numbering: {
        pattern: `${key.toUpperCase()}/{yyyy}/{seq}`,
        padding: 6,
        reset: 'monthly',
        policy: 'on_create',
    },
    versions: [{ version: 1, mode: 'free_lines', status, fields: null }],
    lock_version: 1,
});

const types = [
    type(
        'general',
        'Jurnal Umum',
        'Jurnal bebas untuk akuntan: memilih akun dan mengisi debit dan kredit sendiri.',
    ),
    type('petty', 'Biaya Kas Kecil', 'Uang kecil yang keluar dari laci kas.'),
    type('plain', 'Tanpa Deskripsi', null),
    type('unfinished', 'Belum Terbit', 'Masih draf.', 'draft'),
];

const accountList = () =>
    json({
        data: [],
        links: {},
        meta: { current_page: 1, last_page: 1, total: 0 },
    });

const routes = (
    permissions: string[] = ['entries.read', 'entries.create'],
) => ({
    ...withPermissions(permissions),
    [`GET ${base}/transaction-types`]: () => json({ data: types }),
    [`GET ${base}/accounts`]: accountList,
});

describe('the catalog of transaction types', () => {
    it('shows each type that can be used as a card with what the accountant wrote about it', async () => {
        mockApi(routes());

        renderApp(`${entriesPath}/new`);

        expect(
            await screen.findByRole('heading', {
                name: 'Pilih jenis transaksi',
            }),
        ).toBeInTheDocument();

        await screen.findByRole('link', { name: /Biaya Kas Kecil/ });

        expect(
            screen.getAllByRole('link', {
                name: /Jurnal Umum|Biaya Kas Kecil|Tanpa Deskripsi/,
            }),
        ).toHaveLength(3);
        expect(
            screen.getByText(/Uang kecil yang keluar dari laci kas/),
        ).toBeInTheDocument();
        // A type without a published version cannot take an entry yet, and nothing is chosen for the operator.
        expect(screen.queryByText('Belum Terbit')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Keterangan')).not.toBeInTheDocument();
    });

    it('is ordered by name, whatever the order of the keys', async () => {
        mockApi(routes());

        renderApp(`${entriesPath}/new`);

        const links = await screen.findAllByRole('link', {
            name: /Biaya Kas Kecil|Jurnal Umum|Tanpa Deskripsi/,
        });

        expect(links.map((link) => link.textContent)).toEqual([
            expect.stringContaining('Biaya Kas Kecil'),
            expect.stringContaining('Jurnal Umum'),
            expect.stringContaining('Tanpa Deskripsi'),
        ]);
    });

    it('narrows by name, key and description', async () => {
        mockApi(routes());

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();
        const search = await screen.findByRole('searchbox', {
            name: 'Cari jenis transaksi',
        });

        await typist.type(search, 'laci');

        expect(screen.getByText('Biaya Kas Kecil')).toBeInTheDocument();
        expect(screen.queryByText('Jurnal Umum')).not.toBeInTheDocument();

        await typist.clear(search);
        await typist.type(search, 'plain');

        expect(screen.getByText('Tanpa Deskripsi')).toBeInTheDocument();
        expect(screen.queryByText('Biaya Kas Kecil')).not.toBeInTheDocument();

        await typist.clear(search);
        await typist.type(search, 'tidak ada yang cocok');

        expect(
            await screen.findByText('Tidak ada jenis yang cocok'),
        ).toBeInTheDocument();
    });

    it('opens the form of the card that is clicked, with the type fixed and a way back', async () => {
        mockApi(routes());

        renderApp(`${entriesPath}/new`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('link', { name: /Biaya Kas Kecil/ }),
        );

        expect(await screen.findByLabelText('Keterangan')).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('combobox', { name: 'Jenis transaksi' }),
            ).getByText('Biaya Kas Kecil'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: 'Jenis transaksi' }),
        ).toBeDisabled();

        await typist.click(screen.getByRole('link', { name: 'Ganti jenis' }));

        expect(
            await screen.findByRole('heading', {
                name: 'Pilih jenis transaksi',
            }),
        ).toBeInTheDocument();
    });

    it('opens the form straight from a link that names the type', async () => {
        mockApi(routes());

        renderApp(`${entriesPath}/new?type=petty`);

        expect(await screen.findByLabelText('Keterangan')).toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'Pilih jenis transaksi' }),
        ).not.toBeInTheDocument();
    });

    it('says so, with a way back, when the linked type is unknown or not published', async () => {
        mockApi(routes());

        renderApp(`${entriesPath}/new?type=unfinished`);

        expect(
            await screen.findByText(
                /"unfinished" tidak ada atau belum diterbitkan/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Ganti jenis' }),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText('Keterangan')).not.toBeInTheDocument();
    });

    it('offers an administrator the first type to create when the company has none', async () => {
        mockApi({
            ...routes(['entries.read', 'entries.create', 'policies.manage']),
            [`GET ${base}/transaction-types`]: () => json({ data: [] }),
        });

        renderApp(`${entriesPath}/new`);

        expect(await screen.findByLabelText('Nama jenis')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Deskripsi (opsional)'),
        ).toBeInTheDocument();
    });

    it('tells everyone else to ask an administrator when there is no type', async () => {
        mockApi({
            ...routes(),
            [`GET ${base}/transaction-types`]: () => json({ data: [] }),
        });

        renderApp(`${entriesPath}/new`);

        expect(
            await screen.findByText(/Setiap entri memerlukan jenis transaksi/),
        ).toBeInTheDocument();
    });

    it('says when no type is published yet', async () => {
        mockApi({
            ...routes(),
            [`GET ${base}/transaction-types`]: () =>
                json({
                    data: [type('unfinished', 'Belum Terbit', null, 'draft')],
                }),
        });

        renderApp(`${entriesPath}/new`);

        expect(
            await screen.findByText(/Belum ada jenis transaksi yang terbit/),
        ).toBeInTheDocument();
    });
});
