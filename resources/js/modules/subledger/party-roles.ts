import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type CreatePartyRoleInput =
    components['schemas']['CreatePartyRoleRequest'];

/** A role a party can hold in the company (SL-FR-01). The OpenAPI document types `lock_version` as a string (a generator limit); the API sends a number. */
export type PartyRole = {
    id: string;
    key: string;
    label: string;
    status: 'active' | 'inactive';
    lock_version: number;
};

/** A role the company may adopt, only a suggestion until it confirms. */
export type PartyRoleTemplate = { key: string; label: string };

export const partyRolesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'party-roles', 'list'],
        queryFn: async (): Promise<PartyRole[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/party-roles', {
                        params: { path: { companyId } },
                    }),
                )
            ).data as unknown as PartyRole[],
    });

export const partyRoleTemplatesQuery = (companyId: string, enabled: boolean) =>
    queryOptions({
        queryKey: ['companies', companyId, 'party-roles', 'templates'],
        enabled,
        queryFn: async (): Promise<PartyRoleTemplate[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/party-role-templates', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

export async function createPartyRole(
    companyId: string,
    body: CreatePartyRoleInput,
): Promise<PartyRole> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/party-roles', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data as unknown as PartyRole;
}

/** `lock_version` is required; the key never changes. */
export async function updatePartyRole(
    companyId: string,
    id: string,
    body: {
        lock_version: number;
        label?: string;
        status?: 'active' | 'inactive';
    },
): Promise<PartyRole> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/party-roles/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data as unknown as PartyRole;
}

/** Adopts the picked roles of the template; `confirmed` is what the user said yes to. */
export async function importPartyRoles(
    companyId: string,
    keys: string[],
): Promise<PartyRole[]> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/party-roles/import-template', {
                params: { path: { companyId } },
                body: { confirmed: true, keys },
            }),
        )
    ).data as unknown as PartyRole[];
}
