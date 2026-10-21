import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

type Schemas = components['schemas'];

// The OpenAPI spec types a few nested fields loosely (Scramble cannot read them from the resources);
// the shapes below are what the API sends (see MembershipResource and RoleResource).
export type MemberRole = { id: string; name: string; is_active: boolean };
export type Membership = Omit<
    Schemas['MembershipResource'],
    'roles' | 'data_scope'
> & {
    roles: MemberRole[];
    /** Dimension value ids the member is limited to, each standing for itself and its descendants. */
    data_scope: string[];
};
export type Role = Omit<Schemas['RoleResource'], 'permissions'> & {
    permissions: string[];
};
export type Invitation = Schemas['InvitationResource'];
export type PermissionEntry = { key: string; resource: string; action: string };
export type SecurityPolicy = Schemas['SecurityPolicyResource'];
export type IntegrationClient = Omit<
    Schemas['IntegrationClientResource'],
    'data_scope'
> & {
    /** Dimension value ids the client is limited to, each standing for itself and its descendants. */
    data_scope: string[];
};
export type IntegrationClientWithSecret = Omit<
    Schemas['IntegrationClientWithSecretResource'],
    'data_scope'
> & { data_scope: string[] };
export type IntegrationScope =
    Schemas['CreateIntegrationClientRequest']['scopes'][number];
export type PermissionKey = NonNullable<
    Schemas['CreateRoleRequest']['permissions']
>[number];

export const INTEGRATION_SCOPES: IntegrationScope[] = [
    'transaction-types:read',
    'financial-events:write',
    'financial-events:read:self',
    'exchange-rates:write',
];

const asMembership = (value: unknown) => value as Membership;
const asRole = (value: unknown) => value as Role;
const asClient = (value: unknown) => value as IntegrationClient;
const asClientWithSecret = (value: unknown) =>
    value as IntegrationClientWithSecret;

export const membershipsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'memberships'],
        queryFn: async (): Promise<Membership[]> =>
            (
                await fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/memberships', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                )
            ).map(asMembership),
    });

export const invitationsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'invitations'],
        queryFn: async (): Promise<Invitation[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/invitations', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export const rolesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'roles'],
        queryFn: async (): Promise<Role[]> =>
            (
                await fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/roles', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                )
            ).map(asRole),
    });

/** The permissions the system knows. The catalog is fixed by the system, so it is kept for a while. */
export const permissionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'permissions'],
        staleTime: 10 * 60_000,
        queryFn: async (): Promise<PermissionEntry[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/permissions', {
                        params: { path: { companyId } },
                    }),
                )
            ).data as PermissionEntry[],
    });

export const securityPolicyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'security-policy'],
        queryFn: async (): Promise<SecurityPolicy> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/security-policy', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

export const integrationClientsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'integration-clients'],
        queryFn: async (): Promise<IntegrationClient[]> =>
            (
                await fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/integration-clients', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                )
            ).map(asClient),
    });

/** Permissions grouped by resource, in catalog order: how a role or a policy lists them. */
export function groupByResource(
    permissions: PermissionEntry[],
): { resource: string; keys: string[] }[] {
    const groups = new Map<string, string[]>();

    for (const { resource, key } of permissions) {
        groups.set(resource, [...(groups.get(resource) ?? []), key]);
    }

    return [...groups].map(([resource, keys]) => ({ resource, keys }));
}

export async function inviteMember(
    companyId: string,
    body: Schemas['CreateInvitationRequest'],
): Promise<Invitation> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/invitations', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

export async function revokeInvitation(
    companyId: string,
    id: string,
): Promise<Invitation> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/invitations/{id}/revoke', {
                params: { path: { companyId, id } },
            }),
        )
    ).data;
}

/** Answers the workspace that was joined. */
export async function acceptInvitation(token: string) {
    return (await unwrap(api.POST('/invitations/accept', { body: { token } })))
        .data;
}

/** `lock_version` is required; `role_ids` and `data_scope` replace what the member has. */
export async function updateMembership(
    companyId: string,
    id: string,
    body: Schemas['UpdateMembershipRequest'],
): Promise<Membership> {
    return asMembership(
        (
            await unwrap(
                api.PATCH('/companies/{companyId}/memberships/{id}', {
                    params: { path: { companyId, id } },
                    body,
                }),
            )
        ).data,
    );
}

export async function revokeMembership(
    companyId: string,
    id: string,
): Promise<Membership> {
    return asMembership(
        (
            await unwrap(
                api.POST('/companies/{companyId}/memberships/{id}/revoke', {
                    params: { path: { companyId, id } },
                }),
            )
        ).data,
    );
}

export async function createRole(
    companyId: string,
    body: Schemas['CreateRoleRequest'],
): Promise<Role> {
    return asRole(
        (
            await unwrap(
                api.POST('/companies/{companyId}/roles', {
                    params: { path: { companyId } },
                    body,
                }),
            )
        ).data,
    );
}

/** `lock_version` is required; `permissions` replaces the role's whole list. */
export async function updateRole(
    companyId: string,
    id: string,
    body: Schemas['UpdateRoleRequest'],
): Promise<Role> {
    return asRole(
        (
            await unwrap(
                api.PATCH('/companies/{companyId}/roles/{id}', {
                    params: { path: { companyId, id } },
                    body,
                }),
            )
        ).data,
    );
}

export async function setRoleStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<Role> {
    const path = `/companies/{companyId}/roles/{id}/${action}` as const;

    return asRole(
        (await unwrap(api.POST(path, { params: { path: { companyId, id } } })))
            .data,
    );
}

/** The whole policy is replaced as a new version; `lock_version` is the `version` that was read. */
export async function saveSecurityPolicy(
    companyId: string,
    body: Schemas['UpdateSecurityPolicyRequest'],
): Promise<SecurityPolicy> {
    return (
        await unwrap(
            api.PUT('/companies/{companyId}/security-policy', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** The response carries the secret, shown this once. */
export async function createIntegrationClient(
    companyId: string,
    body: Schemas['CreateIntegrationClientRequest'],
): Promise<IntegrationClientWithSecret> {
    return asClientWithSecret(
        (
            await unwrap(
                api.POST('/companies/{companyId}/integration-clients', {
                    params: { path: { companyId } },
                    body,
                }),
            )
        ).data,
    );
}

export async function updateIntegrationClient(
    companyId: string,
    id: string,
    body: Schemas['UpdateIntegrationClientRequest'],
): Promise<IntegrationClient> {
    return asClient(
        (
            await unwrap(
                api.PATCH('/companies/{companyId}/integration-clients/{id}', {
                    params: { path: { companyId, id } },
                    body,
                }),
            )
        ).data,
    );
}

/** Replaces the secret and ends every active token; the new secret is shown this once. */
export async function rotateIntegrationSecret(
    companyId: string,
    id: string,
): Promise<IntegrationClientWithSecret> {
    return asClientWithSecret(
        (
            await unwrap(
                api.POST(
                    '/companies/{companyId}/integration-clients/{id}/rotate-secret',
                    { params: { path: { companyId, id } } },
                ),
            )
        ).data,
    );
}

export async function revokeIntegrationClient(
    companyId: string,
    id: string,
): Promise<IntegrationClient> {
    return asClient(
        (
            await unwrap(
                api.POST(
                    '/companies/{companyId}/integration-clients/{id}/revoke',
                    { params: { path: { companyId, id } } },
                ),
            )
        ).data,
    );
}
