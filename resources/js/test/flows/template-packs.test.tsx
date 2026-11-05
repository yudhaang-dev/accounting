import { screen } from '@testing-library/react';
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
const path = `/w/${workspace.company_id}/template-packs`;

const withPermissions = (permissions: string[]) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const loans = {
    key: 'addon-loans',
    name: 'Kasbon dan Pinjaman',
    description: 'Akun dan jenis transaksi kasbon.',
    version: 1,
    components: [
        {
            id: 'chart_of_accounts',
            kind: 'chart_of_accounts',
            template: 'addon-loans',
            name: 'Kasbon',
            version: 1,
            items: [
                {
                    key: '1171',
                    label: '1171 Piutang Karyawan',
                    exists: false,
                    optional: false,
                    conflict: null,
                },
                {
                    key: '2150',
                    label: '2150 Pinjaman Diterima',
                    exists: false,
                    optional: false,
                    conflict: 'Kode dipakai akun lain.',
                },
            ],
        },
    ],
};

const servedPack = () => ({
    [`GET ${base}/template-packs`]: () =>
        json({
            data: [
                {
                    key: loans.key,
                    name: loans.name,
                    description: loans.description,
                    version: 1,
                    components: [
                        {
                            id: 'chart_of_accounts',
                            kind: 'chart_of_accounts',
                            template: 'addon-loans',
                        },
                    ],
                },
            ],
        }),
    [`GET ${base}/template-packs/addon-loans`]: () => json({ data: loans }),
    [`POST ${base}/template-packs/addon-loans/install`]: () =>
        json(
            {
                data: {
                    pack: 'addon-loans',
                    version: 1,
                    components: [
                        {
                            id: 'chart_of_accounts',
                            template: 'addon-loans',
                            created: ['1171'],
                            skipped: ['2150'],
                        },
                    ],
                },
            },
            201,
        ),
});

describe('template packs of a running company', () => {
    it('installs an add-on after the review is confirmed and leaves the clash out', async () => {
        const api = mockApi({
            ...withPermissions(['accounts.manage']),
            ...servedPack(),
        });

        renderApp(path);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('checkbox', {
                name: 'Saya telah meninjau isi paket ini dan ingin memasangnya.',
            }),
        );
        await typist.click(screen.getByRole('button', { name: 'Pasang paket' }));

        expect(await screen.findByText('Paket terpasang')).toBeInTheDocument();
        expect(
            api.called(`POST ${base}/template-packs/addon-loans/install`)[0]
                ?.body,
        ).toEqual({
            confirmed: true,
            components: ['chart_of_accounts'],
            exclude: { chart_of_accounts: ['2150'] },
        });

        await typist.click(
            screen.getByRole('button', { name: 'Pasang paket lain' }),
        );
        expect(
            await screen.findByRole('button', { name: 'Pasang paket' }),
        ).toBeInTheDocument();
    });

    it('says so when no pack is released', async () => {
        mockApi({
            ...withPermissions(['accounts.manage']),
            [`GET ${base}/template-packs`]: () => json({ data: [] }),
        });

        renderApp(path);

        expect(
            await screen.findByText('Belum ada paket yang dirilis.'),
        ).toBeInTheDocument();
    });

    it('is not offered to someone who may not manage accounts', async () => {
        mockApi({
            ...withPermissions(['accounts.read']),
            ...servedPack(),
        });

        const { router } = renderApp(path);

        await screen.findByRole('heading', { name: 'PT Toko Budi' });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
    });
});
