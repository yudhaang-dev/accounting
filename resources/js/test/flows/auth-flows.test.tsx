import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    account,
    json,
    mockApi,
    renderApp,
    signedIn,
    signedOut,
} from '@/test/harness';

describe('two-step verification', () => {
    const pending = { ...signedOut };

    it('sends the code by itself as soon as the sixth digit is typed', async () => {
        let session = false;
        const api = mockApi({
            'GET /api/v1/me': () =>
                session ? json({ data: account }) : json({ message: 'x' }, 401),
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
            'POST /two-factor-challenge': () => {
                session = true;

                return new Response(null, { status: 204 });
            },
        });

        renderApp('/two-factor-challenge');
        const typist = userEvent.setup();
        const input = await screen.findByLabelText('Kode autentikasi');

        expect(input).toHaveFocus();
        await typist.type(input, '123456');

        expect(
            await screen.findByRole('heading', { name: 'Ruang kerja baru' }),
        ).toBeInTheDocument();
        expect(api.called('POST /two-factor-challenge')[0]?.body).toEqual({
            code: '123456',
        });
    });

    it('clears a wrong code, explains it, and returns the cursor for the next try', async () => {
        mockApi({
            ...pending,
            'POST /two-factor-challenge': () =>
                json(
                    { message: 'invalid', errors: { code: ['invalid'] } },
                    422,
                ),
        });

        renderApp('/two-factor-challenge');
        const typist = userEvent.setup();
        const input = await screen.findByLabelText('Kode autentikasi');
        await typist.type(input, '000000');

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Kode tidak sesuai',
        );
        await waitFor(() => expect(input).toHaveValue(''));
        await waitFor(() => expect(input).toHaveFocus());
    });

    it('tells people whose half-open login timed out to start over', async () => {
        mockApi({
            ...pending,
            'POST /two-factor-challenge': () =>
                json({ message: 'gone', errors: {} }, 422),
        });

        renderApp('/two-factor-challenge');
        const typist = userEvent.setup();
        await typist.type(
            await screen.findByLabelText('Kode autentikasi'),
            '111111',
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Waktu masuk Anda habis',
        );
        expect(
            screen.getByRole('link', { name: 'Kembali ke halaman masuk' }),
        ).toBeInTheDocument();
    });

    it('offers a recovery code and sends it trimmed', async () => {
        const api = mockApi({
            ...pending,
            'POST /two-factor-challenge': () =>
                new Response(null, { status: 204 }),
        });

        renderApp('/two-factor-challenge');
        const typist = userEvent.setup();
        await typist.click(
            await screen.findByRole('button', {
                name: 'Gunakan kode pemulihan',
            }),
        );

        const field = await screen.findByLabelText('Kode pemulihan');

        expect(field).toHaveFocus();
        await typist.type(field, ' abcde-12345 ');
        await typist.click(screen.getByRole('button', { name: 'Verifikasi' }));

        await waitFor(() =>
            expect(api.called('POST /two-factor-challenge')).toHaveLength(1),
        );
        expect(api.called('POST /two-factor-challenge')[0]?.body).toEqual({
            recovery_code: 'abcde-12345',
        });
    });

    it('keeps the verify button disabled until there is something to verify', async () => {
        mockApi({ ...pending });
        renderApp('/two-factor-challenge');

        expect(
            await screen.findByRole('button', { name: 'Verifikasi' }),
        ).toBeDisabled();
    });
});

describe('forgot password', () => {
    it('confirms by showing where the e-mail went, with a resend cooldown', async () => {
        const api = mockApi({
            ...signedOut,
            'POST /forgot-password': () => json({ message: 'sent' }),
        });

        renderApp('/forgot-password');
        const typist = userEvent.setup();
        await typist.type(
            await screen.findByLabelText('Email'),
            'budi@example.com',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Kirim tautan' }),
        );

        expect(
            await screen.findByText('Periksa email Anda'),
        ).toBeInTheDocument();
        expect(screen.getByText(/budi@example\.com/)).toBeInTheDocument();
        expect(api.called('POST /forgot-password')[0]?.body).toEqual({
            email: 'budi@example.com',
        });

        // Resending right away is blocked and the wait is visible.
        expect(
            screen.getByRole('button', { name: /Kirim ulang dalam \d+ detik/ }),
        ).toBeDisabled();
    });

    it('prefills the e-mail typed on the login screen', async () => {
        mockApi({ ...signedOut });
        renderApp('/forgot-password?email=budi%40example.com');

        expect(await screen.findByLabelText('Email')).toHaveValue(
            'budi@example.com',
        );
    });

    it('shows the server reason under the field', async () => {
        mockApi({
            ...signedOut,
            'POST /forgot-password': () =>
                json(
                    {
                        message: 'x',
                        errors: { email: ['Email tidak ditemukan.'] },
                    },
                    422,
                ),
        });

        renderApp('/forgot-password');
        const typist = userEvent.setup();
        await typist.type(
            await screen.findByLabelText('Email'),
            'nobody@example.com',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Kirim tautan' }),
        );

        expect(
            await screen.findByText('Email tidak ditemukan.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Email')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });
});

describe('reset password', () => {
    const fill = async (password: string, confirmation: string) => {
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Kata sandi baru'),
            password,
        );
        await typist.type(
            screen.getByLabelText('Ulangi kata sandi baru'),
            confirmation,
        );

        return typist;
    };

    it('catches a mismatch as soon as the user leaves the field', async () => {
        mockApi({ ...signedOut });
        renderApp('/reset-password/tok123?email=budi%40example.com');

        const typist = await fill('new-password-1', 'new-password-2');
        await typist.tab();

        expect(
            await screen.findByText('Kata sandi belum sama.'),
        ).toBeInTheDocument();
    });

    it('sends the token from the link and returns to login on success', async () => {
        const api = mockApi({
            ...signedOut,
            'POST /reset-password': () => json({ message: 'ok' }),
        });

        renderApp('/reset-password/tok123?email=budi%40example.com');
        const typist = await fill('new-password-1', 'new-password-1');
        await typist.click(
            screen.getByRole('button', { name: 'Simpan kata sandi' }),
        );

        expect(
            await screen.findByRole('heading', {
                name: 'Selamat datang kembali',
            }),
        ).toBeInTheDocument();
        expect(api.called('POST /reset-password')[0]?.body).toEqual({
            token: 'tok123',
            email: 'budi@example.com',
            password: 'new-password-1',
            password_confirmation: 'new-password-1',
        });
    });

    it('points to a fresh link when the old one is dead', async () => {
        mockApi({
            ...signedOut,
            'POST /reset-password': () =>
                json(
                    { message: 'x', errors: { email: ['Token tidak valid.'] } },
                    422,
                ),
        });

        renderApp('/reset-password/stale?email=budi%40example.com');
        const typist = await fill('new-password-1', 'new-password-1');
        await typist.click(
            screen.getByRole('button', { name: 'Simpan kata sandi' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Token tidak valid.',
        );
        expect(
            screen.getByRole('link', { name: 'Minta tautan baru' }),
        ).toBeInTheDocument();
    });
});

describe('sign out', () => {
    it('ends the session, says so, and returns to the login screen', async () => {
        let session = true;
        const api = mockApi({
            'GET /api/v1/me': () =>
                session ? json({ data: account }) : json({ message: 'x' }, 401),
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
            'POST /logout': () => {
                session = false;

                return new Response(null, { status: 204 });
            },
        });

        const { router } = renderApp('/');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Buka menu akun' }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Keluar' }),
        );

        expect(
            await screen.findByRole('heading', {
                name: 'Selamat datang kembali',
            }),
        ).toBeInTheDocument();
        expect(api.called('POST /logout')).toHaveLength(1);
        expect(router.state.location.pathname).toBe('/login');
    });
});

describe('e-mail verification', () => {
    it('lets the user resend the link and waits before the next one', async () => {
        const api = mockApi({
            'GET /api/v1/me': () =>
                json({ data: { ...account, email_verified: false } }),
            'POST /email/verification-notification': () =>
                json({ message: 'sent' }),
        });

        renderApp('/email/verify');
        const typist = userEvent.setup();
        await typist.click(
            await screen.findByRole('button', {
                name: 'Kirim ulang email verifikasi',
            }),
        );

        expect(
            await screen.findByRole('button', {
                name: /Kirim ulang dalam \d+ detik/,
            }),
        ).toBeDisabled();
        expect(
            api.called('POST /email/verification-notification'),
        ).toHaveLength(1);
    });

    it('does not trap verified users on the verification screen', async () => {
        mockApi({ ...signedIn });
        const { router } = renderApp('/email/verify');

        expect(
            await screen.findByRole('heading', { name: 'Ruang kerja baru' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/workspaces/new');
    });
});
