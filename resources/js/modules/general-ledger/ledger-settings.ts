import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import type { AccountClass } from '@/modules/general-ledger/accounts';

export type LedgerPolicy = components['schemas']['LedgerPolicyResource'];
export type LedgerPolicyInput =
    components['schemas']['UpdateLedgerPolicyRequest'];

/** Roles of the ledger, in the order the screen lists them (GL-FR-10). */
export const ACCOUNT_ROLES = [
    'retained_earnings',
    'fx_realized_gain',
    'fx_realized_loss',
    'fx_unrealized_gain',
    'fx_unrealized_loss',
    'fx_rounding',
] as const;

export type AccountRoleKey = (typeof ACCOUNT_ROLES)[number];

/** Keys the system itself posts under; they need no transaction type (GL-FR-04). */
export const SYSTEM_POSTING_KEYS = [
    'system.closing',
    'system.fx_revaluation',
] as const;

/**
 * One role with the account that plays it. The OpenAPI document types `valid` and `allowed_classes`
 * loosely (a limit of the generator); this is what the API sends.
 */
export type AccountRoleRow = {
    key: AccountRoleKey;
    account_id: string | null;
    effective_date: string | null;
    /** False when the mapped account no longer qualifies, for example because it was deactivated. */
    valid: boolean;
    /** The situation in which a missing mapping blocks a feature. */
    required: 'year_end_close' | 'foreign_currency';
    allowed_classes: AccountClass[];
    /** Version of the mapping; 0 while the role is not mapped. */
    lock_version: number;
};

export const accountRolesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'account-roles'],
        queryFn: async (): Promise<AccountRoleRow[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/account-roles', {
                        params: { path: { companyId } },
                    }),
                )
            ).data as unknown as AccountRoleRow[],
    });

/** Maps the role to an account as a new version; `lockVersion` is the one that was read. */
export async function mapAccountRole(
    companyId: string,
    role: AccountRoleKey,
    accountId: string,
    lockVersion: number,
) {
    return (
        await unwrap(
            api.PUT('/companies/{companyId}/account-roles/{role}', {
                params: { path: { companyId, role } },
                body: { lock_version: lockVersion, account_id: accountId },
            }),
        )
    ).data;
}

export const ledgerPolicyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'ledger-policy'],
        queryFn: async (): Promise<LedgerPolicy> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/ledger-policy', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

/** The whole policy is replaced as a new version; `lock_version` is the version that was read. */
export async function saveLedgerPolicy(
    companyId: string,
    body: LedgerPolicyInput,
): Promise<LedgerPolicy> {
    return (
        await unwrap(
            api.PUT('/companies/{companyId}/ledger-policy', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}
