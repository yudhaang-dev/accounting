import { queryOptions } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { components } from '@/api/schema';

export type Workspace = components['schemas']['WorkspaceResource'];

/**
 * Workspaces the signed-in user belongs to, each with its company and the permissions held there
 * (IA-FR-01). Refreshed explicitly: after a workspace is created, or its membership changes.
 */
export const workspacesQuery = queryOptions({
    queryKey: ['me', 'workspaces'],
    staleTime: Infinity,
    queryFn: async (): Promise<Workspace[]> => {
        const { data } = await api.GET('/me/workspaces');

        if (!data) {
            throw new Error('Unexpected /me/workspaces response');
        }

        return data.data;
    },
});

// The last company a user worked in is a convenience only; storage may be blocked or empty, so every
// access is guarded and the app behaves the same without it.
const storageKey = (userId: number) => `accounting.lastCompany.${userId}`;

export function readLastCompany(userId: number): string | null {
    try {
        return window.localStorage.getItem(storageKey(userId));
    } catch {
        return null;
    }
}

export function rememberLastCompany(userId: number, companyId: string): void {
    try {
        window.localStorage.setItem(storageKey(userId), companyId);
    } catch {
        // Not remembering is fine.
    }
}
