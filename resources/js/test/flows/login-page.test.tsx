import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { json, mockApi, renderApp, signedIn, signedOut } from '@/test/harness';
import { account } from '@/test/harness';

const fill = async (email = 'budi@example.com', password = 'secret-pass') => {
    const typist = userEvent.setup();

    await typist.type(await screen.findByLabelText('Email'), email);
    await typist.type(screen.getByLabelText('Kata sandi'), password);

    return typist;
};

describe('login screen', () => {
    it('puts the cursor in the email field so people can start typing at once', async () => {
        mockApi({ ...signedOut });
        renderApp('/login');

        expect(await screen.findByLabelText('Email')).toHaveFocus();
    });

    it('signs in and lands on the home screen with a greeting', async () => {
        let session = false;
        const api = mockApi({
            'GET /api/v1/me': () =>
                session
                    ? json({ data: account })
                    : json({ message: 'x', code: 'UNAUTHENTICATED' }, 401),
            'GET /api/v1/me/workspaces': () => json({ data: [] }),
            'POST /login': () => {
                session = true;

                return json({ two_factor: false });
            },
        });

        renderApp('/login');
        const typist = await fill();
        await typist.click(screen.getByRole('button', { name: 'Masuk' }));

        expect(
            await screen.findByRole('heading', { name: 'Ruang kerja baru' }),
        ).toBeInTheDocument();
        expect(api.called('POST /login')[0]?.body).toEqual({
            email: 'budi@example.com',
            password: 'secret-pass',
            remember: false,
        });
    });

    it('sends "remember me" when ticked', async () => {
        const api = mockApi({
            ...signedOut,
            'POST /login': () =>
                json({ message: 'no', errors: { email: ['no'] } }, 422),
        });

        renderApp('/login');
        const typist = await fill();
        await typist.click(
            screen.getByRole('checkbox', {
                name: 'Ingat saya di perangkat ini',
            }),
        );
        await typist.click(screen.getByRole('button', { name: 'Masuk' }));

        await waitFor(() => expect(api.called('POST /login')).toHaveLength(1));
        expect(api.called('POST /login')[0]?.body).toMatchObject({
            remember: true,
        });
    });

    it('explains a wrong password in the form and hands the cursor back to the password', async () => {
        mockApi({
            ...signedOut,
            'POST /login': () =>
                json(
                    {
                        message: 'Kredensial ini tidak cocok dengan data kami.',
                        errors: {
                            email: [
                                'Kredensial ini tidak cocok dengan data kami.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp('/login');
        const typist = await fill();
        await typist.click(screen.getByRole('button', { name: 'Masuk' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Kredensial ini tidak cocok dengan data kami.',
        );
        expect(screen.getByLabelText('Kata sandi')).toHaveFocus();
        expect(screen.getByLabelText('Email')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        // The button is usable again for the next attempt.
        expect(screen.getByRole('button', { name: 'Masuk' })).toBeEnabled();
    });

    it('asks for the missing fields instead of calling the server', async () => {
        const api = mockApi({ ...signedOut });

        renderApp('/login');
        const typist = userEvent.setup();
        await typist.click(
            await screen.findByRole('button', { name: 'Masuk' }),
        );

        expect(await screen.findAllByText('Wajib diisi')).toHaveLength(2);
        expect(screen.getByLabelText('Email')).toHaveFocus();
        expect(api.called('POST /login')).toHaveLength(0);
    });

    it('shows progress and blocks a second submit while the request is running', async () => {
        let release: (value: Response) => void = () => undefined;
        const api = mockApi({
            ...signedOut,
            'POST /login': () =>
                new Promise<Response>((resolve) => (release = resolve)),
        });

        renderApp('/login');
        const typist = await fill();
        await typist.click(screen.getByRole('button', { name: 'Masuk' }));

        const busy = await screen.findByRole('button', { name: 'Memeriksa…' });

        expect(busy).toBeDisabled();
        expect(busy).toHaveAttribute('aria-busy', 'true');

        await typist.click(busy);
        expect(api.called('POST /login')).toHaveLength(1);

        release(json({ message: 'no', errors: { email: ['no'] } }, 422));
        expect(
            await screen.findByRole('button', { name: 'Masuk' }),
        ).toBeEnabled();
    });

    it('counts down when the server throttles sign-in attempts', async () => {
        mockApi({
            ...signedOut,
            'POST /login': () =>
                json({ message: 'Too many', code: 'TOO_MANY_REQUESTS' }, 429, {
                    'Retry-After': '30',
                }),
        });

        renderApp('/login');
        const typist = await fill();
        await typist.click(screen.getByRole('button', { name: 'Masuk' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Terlalu banyak percobaan masuk.',
        );

        const waiting = await screen.findByRole('button', {
            name: /Coba lagi dalam \d+ detik/,
        });

        expect(waiting).toBeDisabled();
    });

    it('goes to the two-step screen when the account has 2FA', async () => {
        mockApi({
            ...signedOut,
            'POST /login': () => json({ two_factor: true }),
        });

        renderApp('/login');
        const typist = await fill();
        await typist.click(screen.getByRole('button', { name: 'Masuk' }));

        expect(
            await screen.findByRole('heading', {
                name: 'Verifikasi dua langkah',
            }),
        ).toBeInTheDocument();
    });

    it('reveals the password on request without losing focus, and warns about Caps Lock', async () => {
        mockApi({ ...signedOut });
        renderApp('/login');

        const typist = userEvent.setup();
        const password = await screen.findByLabelText('Kata sandi');
        await typist.click(password);

        const toggle = screen.getByRole('button', {
            name: 'Tampilkan kata sandi',
        });

        expect(password).toHaveAttribute('type', 'password');
        await typist.click(toggle);
        expect(password).toHaveAttribute('type', 'text');
        expect(password).toHaveFocus();
        expect(
            screen.getByRole('button', { name: 'Sembunyikan kata sandi' }),
        ).toHaveAttribute('aria-pressed', 'true');

        password.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'A',
                bubbles: true,
                modifierCapsLock: true,
            }),
        );
        expect(
            await screen.findByText('Caps Lock sedang aktif'),
        ).toBeInTheDocument();
    });

    it('hides the passkey option on browsers without WebAuthn', async () => {
        mockApi({ ...signedOut });
        renderApp('/login');

        await screen.findByLabelText('Email');
        expect(
            screen.queryByRole('button', { name: 'Masuk dengan passkey' }),
        ).not.toBeInTheDocument();
    });

    it('switches language and remembers it', async () => {
        mockApi({ ...signedOut });
        renderApp('/login');

        const typist = userEvent.setup();
        await typist.click(
            await screen.findByRole('button', { name: 'Bahasa' }),
        );
        await typist.click(
            await screen.findByRole('menuitem', { name: 'English' }),
        );

        expect(
            await screen.findByRole('button', { name: 'Sign in' }),
        ).toBeInTheDocument();
        expect(localStorage.getItem('locale')).toBe('en');
        expect(document.documentElement.lang).toBe('en');

        // The menu names the current language on its button, in that language.
        await typist.click(screen.getByRole('button', { name: 'Language' }));
        await typist.click(
            await screen.findByRole('menuitem', { name: 'Indonesia' }),
        );
        expect(
            await screen.findByRole('button', { name: 'Masuk' }),
        ).toBeInTheDocument();
    });
});

describe('route guards', () => {
    it('sends a signed-out visitor to login and remembers where they were going', async () => {
        mockApi({ ...signedOut });
        const { router } = renderApp('/');

        await screen.findByLabelText('Email');

        expect(router.state.location.pathname).toBe('/login');
        expect(router.state.location.search).toEqual({ redirect: '/' });
    });

    it('keeps a signed-in visitor off the login screen', async () => {
        mockApi({ ...signedIn });
        const { router } = renderApp('/login');

        expect(
            await screen.findByRole('heading', { name: 'Ruang kerja baru' }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/workspaces/new');
    });

    it('parks an unverified account on the verification screen', async () => {
        mockApi({
            'GET /api/v1/me': () =>
                json({ data: { ...account, email_verified: false } }),
        });
        const { router } = renderApp('/');

        expect(
            await screen.findByRole('heading', {
                name: 'Verifikasi email Anda',
            }),
        ).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/email/verify');
    });

    it('answers unknown addresses with a friendly not-found screen', async () => {
        mockApi({ ...signedOut });
        renderApp('/nope/never');

        expect(
            await screen.findByRole('heading', {
                name: 'Halaman tidak ditemukan',
            }),
        ).toBeInTheDocument();
    });
});
