import { queryOptions, type QueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';

export type Me = {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    two_factor_enabled: boolean;
};

/**
 * The signed-in user, or `null` when there is no session. The router reads it before rendering
 * any screen, so an authenticated visitor never sees a flash of the login form (and vice versa).
 * It is only refreshed explicitly: after login, logout, or a 401.
 */
export const meQuery = queryOptions({
    queryKey: ['me'],
    staleTime: Infinity,
    queryFn: async (): Promise<Me | null> => {
        const { data, response } = await api.GET('/me');

        if (response.status === 401) {
            return null;
        }

        if (!data) {
            throw new Error(`Unexpected /me response (${response.status})`);
        }

        return data.data as Me;
    },
});

export async function refreshMe(queryClient: QueryClient): Promise<Me | null> {
    return queryClient.fetchQuery({ ...meQuery, staleTime: 0 });
}

export function clearMe(queryClient: QueryClient): void {
    queryClient.setQueryData(meQuery.queryKey, null);
}

/** Only same-site relative paths may be used as a post-login destination (no open redirect). */
export function safeRedirect(value: unknown): string | undefined {
    if (
        typeof value === 'string' &&
        value.startsWith('/') &&
        !value.startsWith('//') &&
        !value.startsWith('/\\')
    ) {
        return value;
    }

    return undefined;
}
