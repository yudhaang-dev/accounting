import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { Me } from '@/modules/identity-access/session';

/**
 * The signed-in user's own account (IA-FR-01, IA-FR-04): profile, address, password, sessions, leaving
 * workspaces, and deactivation. Every call acts on the caller only.
 */

export type AccountSession = {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active_at: string;
    is_current: boolean;
};

export const sessionsQuery = queryOptions({
    queryKey: ['me', 'sessions'],
    queryFn: async (): Promise<AccountSession[]> =>
        (
            await request<{ data: AccountSession[] }>(
                'GET',
                '/api/v1/me/sessions',
            )
        ).data,
});

export const accountApi = {
    rename: async (name: string): Promise<Me> =>
        (await request<{ data: Me }>('PATCH', '/api/v1/me', { name })).data,

    requestEmailChange: (input: { email: string; current_password: string }) =>
        request('POST', '/api/v1/me/email', input),

    confirmEmailChange: async (token: string): Promise<Me> =>
        (
            await request<{ data: Me }>('POST', '/api/v1/me/email/confirm', {
                token,
            })
        ).data,

    changePassword: (input: {
        current_password: string;
        password: string;
        password_confirmation: string;
    }) => request('PUT', '/api/v1/me/password', input),

    endSession: (id: string) =>
        request('DELETE', `/api/v1/me/sessions/${encodeURIComponent(id)}`),

    endOtherSessions: () => request('DELETE', '/api/v1/me/sessions'),

    leaveWorkspace: (workspaceId: string) =>
        request('POST', `/api/v1/me/workspaces/${workspaceId}/leave`),

    deactivate: (currentPassword: string) =>
        request('POST', '/api/v1/me/deactivate', {
            current_password: currentPassword,
        }),
};

/**
 * Fortify's routes for two-step verification. They live at the site root and refuse with 423 until the
 * password was confirmed recently (`user/confirm-password`).
 */
export const twoFactorApi = {
    confirmPassword: (password: string) =>
        request('POST', '/user/confirm-password', { password }),

    enable: () => request('POST', '/user/two-factor-authentication'),

    qrCode: () =>
        request<{ svg: string; url: string }>(
            'GET',
            '/user/two-factor-qr-code',
        ),

    secretKey: async (): Promise<string> =>
        (
            await request<{ secretKey: string }>(
                'GET',
                '/user/two-factor-secret-key',
            )
        ).secretKey,

    confirm: (code: string) =>
        request('POST', '/user/confirmed-two-factor-authentication', { code }),

    recoveryCodes: () =>
        request<string[]>('GET', '/user/two-factor-recovery-codes'),

    regenerateRecoveryCodes: () =>
        request('POST', '/user/two-factor-recovery-codes'),

    disable: () => request('DELETE', '/user/two-factor-authentication'),
};

export type AccountPasskey = {
    id: string;
    name: string;
    /** The kind of authenticator, when the device reports one (for example "iCloud Keychain"). */
    authenticator: string | null;
    created_at: string | null;
    last_used_at: string | null;
};

export const passkeysQuery = queryOptions({
    queryKey: ['me', 'passkeys'],
    queryFn: async (): Promise<AccountPasskey[]> =>
        (
            await request<{ data: AccountPasskey[] }>(
                'GET',
                '/api/v1/me/passkeys',
            )
        ).data,
});

/**
 * Adding a passkey runs a browser ceremony (`@laravel/passkeys`); removing one is Fortify's own route. Both
 * ask to confirm the password first, which `passwordConfirmed` lets a screen check before it starts.
 */
export const passkeyApi = {
    passwordConfirmed: async (): Promise<boolean> =>
        (
            await request<{ confirmed: boolean }>(
                'GET',
                '/user/confirmed-password-status',
            )
        ).confirmed,

    remove: (id: string) =>
        request('DELETE', `/user/passkeys/${encodeURIComponent(id)}`),
};
