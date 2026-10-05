import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import type { AccountClass } from '@/modules/general-ledger/accounts';

export type CreatePostingProfileInput =
    components['schemas']['CreatePostingProfileRequest'];
export type UpdatePostingProfileInput =
    components['schemas']['UpdatePostingProfileRequest'];

/** Control roles of a profile, in the order the screen lists them, with the class of account each takes (SL-FR-02). */
export const CONTROL_ROLES = [
    { key: 'receivables_control', accountClass: 'asset' },
    { key: 'payables_control', accountClass: 'liability' },
] as const satisfies readonly { key: string; accountClass: AccountClass }[];

export type ControlRoleKey = (typeof CONTROL_ROLES)[number]['key'];

/** The account that plays one control role now. The OpenAPI document types `valid` as a string (a generator limit); the API sends a boolean. */
export type ControlRoleRow = {
    key: ControlRoleKey;
    account_id: string | null;
    effective_date: string | null;
    /** False when the mapped account no longer qualifies, for example because it was deactivated. */
    valid: boolean;
    /** Version of the mapping; 0 while the role is not mapped. */
    lock_version: number;
};

export type PostingProfile = {
    id: string;
    name: string;
    is_default: boolean;
    lock_version: number;
    accounts: ControlRoleRow[];
};

export const postingProfilesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'posting-profiles'],
        queryFn: async (): Promise<PostingProfile[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/posting-profiles', {
                        params: { path: { companyId } },
                    }),
                )
            ).data as unknown as PostingProfile[],
    });

export async function createPostingProfile(
    companyId: string,
    body: CreatePostingProfileInput,
) {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/posting-profiles', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the default can be moved to another profile but not cleared. */
export async function updatePostingProfile(
    companyId: string,
    id: string,
    body: UpdatePostingProfileInput,
) {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/posting-profiles/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

/** Maps the role to an account as a new version; `lockVersion` is the one that was read. */
export async function mapControlAccount(
    companyId: string,
    id: string,
    role: ControlRoleKey,
    accountId: string,
    lockVersion: number,
) {
    return (
        await unwrap(
            api.PUT(
                '/companies/{companyId}/posting-profiles/{id}/accounts/{role}',
                {
                    params: { path: { companyId, id, role } },
                    body: { lock_version: lockVersion, account_id: accountId },
                },
            ),
        )
    ).data;
}
