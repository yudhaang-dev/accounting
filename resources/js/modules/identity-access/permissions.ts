import type { Workspace } from '@/modules/identity-access/workspaces';

/**
 * Whether the user holds a permission in a workspace's company (IA-FR-02). This only decides what
 * the screen offers; the API enforces every permission itself, so a hidden button is a courtesy,
 * not the guard.
 */
export function can(
    workspace: Pick<Workspace, 'permissions'> | undefined,
    permission: string,
): boolean {
    return workspace?.permissions.includes(permission) ?? false;
}
