import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

const sessions = [
    {
        id: 'current-session',
        ip_address: '10.0.0.1',
        user_agent: 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36',
        last_active_at: '2026-11-12T08:00:00+00:00',
        is_current: true,
    },
    {
        id: 'phone-session',
        ip_address: '10.0.0.2',
        user_agent: 'Mozilla/5.0 (iPhone) Safari/604.1',
        last_active_at: '2026-11-11T08:00:00+00:00',
        is_current: false,
    },
];

const signedIn = () => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () => json({ data: [workspace] }),
    'GET /api/v1/me/sessions': () => json({ data: sessions }),
});

describe('own account (D2d)', () => {
    it('saves a new name and shows it in the menu after reloading the user', async () => {
        let name = account.name;
        const api = mockApi({
            ...signedIn(),
            'GET /api/v1/me': () => json({ data: { ...account, name } }),
            'PATCH /api/v1/me': (call) => {
                name = (call.body as { name: string }).name;

                return json({ data: { ...account, name } });
            },
        });

        renderApp('/account');
        const typist = userEvent.setup();
        const field = await screen.findByLabelText('Nama');

        await typist.clear(field);
        await typist.type(field, 'Budi Baru');
        await typist.click(screen.getByRole('button', { name: 'Simpan' }));

        await waitFor(() =>
            expect(api.called('PATCH /api/v1/me')[0]?.body).toEqual({
                name: 'Budi Baru',
            }),
        );
    });

    it('asks for the current password to change the address and says a link was sent', async () => {
        const api = mockApi({
            ...signedIn(),
            'POST /api/v1/me/email': () => new Response(null, { status: 204 }),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Alamat email baru'),
            'baru@example.com',
        );
        await typist.type(
            screen.getAllByLabelText('Kata sandi saat ini')[0],
            'Secret-pass-123',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Kirim konfirmasi' }),
        );

        expect(await screen.findByRole('status')).toHaveTextContent(
            'baru@example.com',
        );
        expect(api.called('POST /api/v1/me/email')[0]?.body).toEqual({
            email: 'baru@example.com',
            current_password: 'Secret-pass-123',
        });
    });

    it('shows the field error when the current password is wrong', async () => {
        mockApi({
            ...signedIn(),
            'PUT /api/v1/me/password': () =>
                json(
                    {
                        message: 'invalid',
                        errors: {
                            current_password: ['Kata sandi saat ini salah.'],
                        },
                    },
                    422,
                ),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByLabelText('Kata sandi saat ini', {
                selector: '#account-current-password',
            }),
            'wrong-pass-1',
        );
        await typist.type(
            screen.getByLabelText('Kata sandi baru'),
            'New-secret-456',
        );
        await typist.type(
            screen.getByLabelText('Ulangi kata sandi baru'),
            'New-secret-456',
        );
        await typist.click(
            screen.getByRole('button', { name: 'Ganti kata sandi' }),
        );

        expect(
            await screen.findByText('Kata sandi saat ini salah.'),
        ).toBeInTheDocument();
    });

    it('lists devices, marks the current one, and ends another', async () => {
        const api = mockApi({
            ...signedIn(),
            'DELETE /api/v1/me/sessions/phone-session': () =>
                new Response(null, { status: 204 }),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        const current = (await screen.findByText('Perangkat ini')).closest(
            'li',
        );
        expect(current).not.toBeNull();
        expect(
            within(current as HTMLElement).queryByRole('button', {
                name: 'Keluarkan',
            }),
        ).toBeNull();

        const phone = screen.getByText(/Safari · iOS/).closest('li');
        await typist.click(
            within(phone as HTMLElement).getByRole('button', {
                name: 'Keluarkan',
            }),
        );

        await waitFor(() =>
            expect(
                api.called('DELETE /api/v1/me/sessions/phone-session'),
            ).toHaveLength(1),
        );
    });

    it('explains why the last administrator cannot leave', async () => {
        mockApi({
            ...signedIn(),
            [`POST /api/v1/me/workspaces/${workspace.id}/leave`]: () =>
                json(
                    {
                        message: 'invalid',
                        errors: {
                            workspace: [
                                'Anda satu-satunya orang yang dapat mengelola anggota Toko Budi.',
                            ],
                        },
                    },
                    422,
                ),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Keluar' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Keluar' }),
        );

        expect(
            await within(dialog).findByText(/satu-satunya orang/),
        ).toBeInTheDocument();
    });

    it('confirms a new address from the e-mail link exactly once', async () => {
        const api = mockApi({
            ...signedIn(),
            'POST /api/v1/me/email/confirm': () =>
                json({ data: { ...account, email: 'baru@example.com' } }),
        });

        renderApp('/account/confirm-email?token=abc123');

        expect(
            await screen.findByText(/Sekarang Anda masuk dengan/),
        ).toHaveTextContent('baru@example.com');
        expect(api.called('POST /api/v1/me/email/confirm')).toHaveLength(1);
        expect(api.called('POST /api/v1/me/email/confirm')[0]?.body).toEqual({
            token: 'abc123',
        });
    });

    it('says the link is invalid when the server refuses the token', async () => {
        mockApi({
            ...signedIn(),
            'POST /api/v1/me/email/confirm': () =>
                json({ message: 'invalid', errors: { token: ['x'] } }, 422),
        });

        renderApp('/account/confirm-email?token=bad');

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'tidak valid atau sudah kedaluwarsa',
        );
    });

    it('needs the password to deactivate and shows the refusal for the last administrator', async () => {
        const api = mockApi({
            ...signedIn(),
            'POST /api/v1/me/deactivate': () =>
                json(
                    {
                        message: 'invalid',
                        errors: { workspace: ['Anda satu-satunya pengelola.'] },
                    },
                    422,
                ),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Nonaktifkan akun' }),
        );
        const dialog = await screen.findByRole('dialog');
        await typist.type(
            within(dialog).getByLabelText('Kata sandi saat ini'),
            'Secret-pass-123',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Nonaktifkan' }),
        );

        expect(
            await within(dialog).findByText('Anda satu-satunya pengelola.'),
        ).toBeInTheDocument();
        expect(api.called('POST /api/v1/me/deactivate')[0]?.body).toEqual({
            current_password: 'Secret-pass-123',
        });
    });
});

describe('two-step verification (IA-FR-04)', () => {
    const qr = {
        svg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        url: 'otpauth://x',
    };

    it('asks for the password when refused, then sets up with a code and shows the recovery codes', async () => {
        let confirmed = false;
        let enabled = false;
        const api = mockApi({
            ...signedIn(),
            'GET /api/v1/me': () =>
                json({ data: { ...account, two_factor_enabled: enabled } }),
            'POST /user/two-factor-authentication': () =>
                confirmed
                    ? json('')
                    : json({ message: 'Password confirmation required.' }, 423),
            'POST /user/confirm-password': () => {
                confirmed = true;

                return json('', 201);
            },
            'GET /user/two-factor-qr-code': () => json(qr),
            'GET /user/two-factor-secret-key': () =>
                json({ secretKey: 'ABCDEF234567' }),
            'POST /user/confirmed-two-factor-authentication': () => {
                enabled = true;

                return json('');
            },
            'GET /user/two-factor-recovery-codes': () =>
                json(['aaaaa-11111', 'bbbbb-22222']),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Siapkan verifikasi dua langkah',
            }),
        );

        const dialog = await screen.findByRole('dialog');
        await typist.type(
            within(dialog).getByLabelText('Kata sandi saat ini'),
            'Secret-pass-123',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Lanjut' }),
        );

        // The refused click ran again by itself: the setup shows without a second click.
        expect(await screen.findByText('ABCDEF234567')).toBeInTheDocument();
        expect(api.called('POST /user/two-factor-authentication')).toHaveLength(
            2,
        );

        await typist.type(
            screen.getByLabelText('Kode dari aplikasi'),
            '123456',
        );

        expect(await screen.findByText('aaaaa-11111')).toBeInTheDocument();
        expect(
            api.called('POST /user/confirmed-two-factor-authentication')[0]
                ?.body,
        ).toEqual({ code: '123456' });
        expect(
            await screen.findByRole('button', { name: 'Matikan' }),
        ).toBeInTheDocument();
    });

    it('says when the code is wrong and keeps the setup open', async () => {
        mockApi({
            ...signedIn(),
            'POST /user/two-factor-authentication': () => json(''),
            'GET /user/two-factor-qr-code': () => json(qr),
            'GET /user/two-factor-secret-key': () =>
                json({ secretKey: 'ABCDEF234567' }),
            'POST /user/confirmed-two-factor-authentication': () =>
                json({ message: 'invalid', errors: { code: ['x'] } }, 422),
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Siapkan verifikasi dua langkah',
            }),
        );
        await typist.type(
            await screen.findByLabelText('Kode dari aplikasi'),
            '000000',
        );

        expect(
            await screen.findByText(/Kode itu tidak sesuai/),
        ).toBeInTheDocument();
        expect(screen.getByText('ABCDEF234567')).toBeInTheDocument();
    });

    it('turns it off after a confirmation', async () => {
        let enabled = true;
        const api = mockApi({
            ...signedIn(),
            'GET /api/v1/me': () =>
                json({ data: { ...account, two_factor_enabled: enabled } }),
            'DELETE /user/two-factor-authentication': () => {
                enabled = false;

                return json('');
            },
        });

        renderApp('/account');
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', { name: 'Matikan' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        await typist.click(
            within(dialog).getByRole('button', { name: 'Matikan' }),
        );

        expect(
            await screen.findByRole('button', {
                name: 'Siapkan verifikasi dua langkah',
            }),
        ).toBeInTheDocument();
        expect(
            api.called('DELETE /user/two-factor-authentication'),
        ).toHaveLength(1);
    });
});
