import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    signedIn,
    signedInWithWorkspace,
    workspace,
} from '@/test/harness';

const second = {
    ...workspace,
    id: '01JWORKSPACE000000000000002',
    name: 'Kedai Sari',
    company_id: '01JCOMPANY00000000000000002',
    company_name: 'CV Kedai Sari',
};

const both = {
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () => json({ data: [workspace, second] }),
    [`GET /api/v1/companies/${workspace.company_id}`]: () =>
        json({ data: company }),
    [`GET /api/v1/companies/${second.company_id}`]: () =>
        json({
            data: {
                ...company,
                id: second.company_id,
                name: second.company_name,
                status: 'active',
            },
        }),
};

describe('workspace selection', () => {
    it('opens the only workspace straight away', async () => {
        mockApi({ ...signedInWithWorkspace });
        const { router } = renderApp('/');

        expect(
            await screen.findByRole('heading', { name: 'PT Toko Budi' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
        expect(screen.getByText('Penyiapan')).toBeInTheDocument();
        expect(
            screen.getByText('Perusahaan masih dalam penyiapan'),
        ).toBeInTheDocument();
    });

    it('lets a user without workspaces know there is nothing to open yet', async () => {
        mockApi({ ...signedIn });
        renderApp('/');

        expect(
            await screen.findByText('Belum ada ruang kerja'),
        ).toBeInTheDocument();
    });

    it('offers a choice when there are several workspaces, and opens the chosen one', async () => {
        mockApi({ ...both });
        const { router } = renderApp('/');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('link', { name: 'Buka Kedai Sari' }),
        );

        expect(
            await screen.findByRole('heading', { name: 'CV Kedai Sari' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe(`/w/${second.company_id}`);
        expect(screen.getByText('Aktif')).toBeInTheDocument();
    });

    it('returns to the workspace used last', async () => {
        localStorage.setItem(
            `accounting.lastCompany.${account.id}`,
            second.company_id,
        );
        mockApi({ ...both });
        const { router } = renderApp('/');

        expect(
            await screen.findByRole('heading', { name: 'CV Kedai Sari' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe(`/w/${second.company_id}`);
    });

    it('ignores a remembered workspace the user no longer belongs to', async () => {
        localStorage.setItem(
            `accounting.lastCompany.${account.id}`,
            '01JCOMPANYGONE0000000000001',
        );
        mockApi({ ...both });
        const { router } = renderApp('/');

        expect(
            await screen.findByRole('link', { name: 'Buka Toko Budi' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/workspaces');
    });

    it('switches workspace from the sidebar', async () => {
        mockApi({ ...both });
        renderApp(`/w/${workspace.company_id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Ganti ruang kerja' }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Kedai Sari' }),
        );

        expect(
            await screen.findByRole('heading', { name: 'CV Kedai Sari' }),
        ).toBeInTheDocument();
    });

    it('treats a company the user is not a member of as not found', async () => {
        const api = mockApi({ ...signedInWithWorkspace });
        renderApp('/w/01JCOMPANYOTHER000000000001');

        expect(
            await screen.findByText('Ruang kerja tidak ditemukan'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Ke daftar ruang kerja' }),
        ).toBeInTheDocument();
        expect(
            api.calls.filter((c) => c.path.includes('/companies/')),
        ).toHaveLength(0);
    });
});
