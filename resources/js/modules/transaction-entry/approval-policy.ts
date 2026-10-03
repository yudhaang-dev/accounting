import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type EntrySource = 'manual' | 'integration' | 'recurring';

export const ENTRY_SOURCES: EntrySource[] = [
    'manual',
    'integration',
    'recurring',
];

/** One rule of the policy (TE-FR-09); a null condition matches everything. */
export type ApprovalRule = {
    transaction_type_key: string | null;
    source: EntrySource | null;
    /** Decimal string; the rule applies above it, in the functional currency. */
    threshold: string | null;
    /** The permission each stage needs, in order; none approves the entry by itself. */
    stages: string[];
    auto_post: boolean;
};

export type ApprovalPolicy = {
    /** 0 while the company has never set a policy. */
    version: number;
    separation_of_duties: boolean;
    rules: ApprovalRule[];
    lock_version: number;
};

export const approvalPolicyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'approval-policy'],
        queryFn: async (): Promise<ApprovalPolicy> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/approval-policy', {
                        params: { path: { companyId } },
                    }),
                )
            ).data as unknown as ApprovalPolicy,
    });

/** The whole policy replaces the old one as a new version; `lock_version` is the version that was read. */
export async function updateApprovalPolicy(
    companyId: string,
    body: Pick<
        ApprovalPolicy,
        'lock_version' | 'separation_of_duties' | 'rules'
    >,
): Promise<ApprovalPolicy> {
    return (
        await unwrap(
            api.PUT('/companies/{companyId}/approval-policy', {
                params: { path: { companyId } },
                // The threshold goes as the decimal string the user typed; the generated type calls it a number.
                body: body as unknown as components['schemas']['UpdateApprovalPolicyRequest'],
            }),
        )
    ).data as unknown as ApprovalPolicy;
}

/** The permissions a stage can require. The catalog is fixed by the system, so it is kept for a while. */
export const stagePermissionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-entry', 'permissions'],
        staleTime: 10 * 60_000,
        queryFn: async (): Promise<string[]> =>
            (
                (
                    await unwrap(
                        api.GET('/companies/{companyId}/permissions', {
                            params: { path: { companyId } },
                        }),
                    )
                ).data as { key: string }[]
            ).map((permission) => permission.key),
    });
