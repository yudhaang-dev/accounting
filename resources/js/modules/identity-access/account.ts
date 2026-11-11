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
