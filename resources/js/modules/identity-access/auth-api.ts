import { request } from '@/lib/http';

/**
 * Fortify's headless JSON routes (login, 2FA, password reset, e-mail verification). They live at the
 * site root, not under /api/v1, so they are not part of the generated OpenAPI client.
 */

export type LoginInput = {
    email: string;
    password: string;
    remember: boolean;
};

export type LoginResult = {
    /** True when the account has 2FA: the session is half-open until the challenge is answered. */
    two_factor: boolean;
};

export const authApi = {
    login: (input: LoginInput) =>
        request<LoginResult>('POST', '/login', input, {
            expectUnauthenticated: true,
        }),

    twoFactorChallenge: (input: { code: string } | { recovery_code: string }) =>
        request('POST', '/two-factor-challenge', input, {
            expectUnauthenticated: true,
        }),

    logout: () =>
        request('POST', '/logout', undefined, { expectUnauthenticated: true }),

    forgotPassword: (email: string) =>
        request<{ message: string }>(
            'POST',
            '/forgot-password',
            { email },
            { expectUnauthenticated: true },
        ),

    resetPassword: (input: {
        token: string;
        email: string;
        password: string;
        password_confirmation: string;
    }) =>
        request<{ message: string }>('POST', '/reset-password', input, {
            expectUnauthenticated: true,
        }),

    resendVerification: () =>
        request<{ message?: string }>(
            'POST',
            '/email/verification-notification',
        ),
};
