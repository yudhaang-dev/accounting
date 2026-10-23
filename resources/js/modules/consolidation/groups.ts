import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components, operations } from '@/api/schema';
import { downloadFile } from '@/lib/download';

type Json<
    Operation extends keyof operations,
    Status extends number,
> = operations[Operation] extends {
    responses: Record<Status, { content: { 'application/json': infer Body } }>;
}
    ? Body
    : never;

type DataOf<Body> = Body extends { data: infer Data } ? Data : never;

/** A group the user holds a grant on, with what the user may do on it (IA-FR-07). */
export type Group = DataOf<Json<'groups.index', 200>>[number];
export type GroupAbility = 'read' | 'run' | 'approve';
export type GroupAccount = components['schemas']['GroupAccountResource'];
export type PerimeterMember = components['schemas']['PerimeterMemberResource'];
export type MappingVersion = DataOf<Json<'groups.account-mappings.show', 200>>;
export type MappingRow = MappingVersion['mappings'][number];
export type MemberAccount = DataOf<
    Json<'groups.member-accounts.index', 200>
>[number];
export type RunSummary = DataOf<Json<'groups.runs.index', 200>>[number];
export type Run = DataOf<Json<'groups.runs.show', 200>>;
export type RunLine = Run['lines'][number];
export type RunSource = RunLine['sources'][number];
export type RunEntity = Run['entities'][number];
export type RunException = Run['exceptions'][number];
export type Adjustment = DataOf<
    Json<'groups.runs.adjustments.index', 200>
>[number];
type AdjustmentRequest = components['schemas']['CreateAdjustmentRequest'];
export type AdjustmentInput = Omit<AdjustmentRequest, 'lines'> & {
    /** Amounts are decimal strings (API-TR-05); the generated type reads the `decimal` rule as a number. */
    lines: {
        group_account_id: string;
        debit: string;
        credit: string;
    }[];
};
export type RateType =
    components['schemas']['App.Modules.Consolidation.Http.RateTypeResource'];
export type GroupRate = components['schemas']['GroupRateResource'];
export type TranslationPolicy = DataOf<
    Json<'groups.translation-policy.show', 200>
>;

export type RateFilters = {
    rate_type_id?: string;
    currency?: string;
    include_superseded?: boolean;
};

export const GROUP_ACCOUNT_CLASSES = [
    'asset',
    'liability',
    'equity',
    'income',
    'expense',
] as const;
export type GroupAccountClass = (typeof GROUP_ACCOUNT_CLASSES)[number];

export type PackagePolicy = 'draft' | 'reject';

export const CASH_FLOW_CLASSES = [
    'cash',
    'operating',
    'investing',
    'financing',
] as const;
export type CashFlowClass = (typeof CASH_FLOW_CLASSES)[number];

/** The classes a group account of a class may take: balance sheet accounts only, and `cash` only for assets. */
export function cashFlowClassesFor(accountClass: string): CashFlowClass[] {
    if (accountClass === 'asset') {
        return [...CASH_FLOW_CLASSES];
    }

    return accountClass === 'liability' || accountClass === 'equity'
        ? CASH_FLOW_CLASSES.filter((value) => value !== 'cash')
        : [];
}

/** Whether a group's abilities include one; reading is included in running and approving. */
export function groupCan(
    group: Pick<Group, 'abilities'> | undefined,
    ability: GroupAbility,
): boolean {
    return group?.abilities.includes(ability) ?? false;
}

const everything = ['groups'] as const;

export const groupsQuery = queryOptions({
    queryKey: [...everything, 'list'],
    queryFn: async (): Promise<Group[]> =>
        (await unwrap(api.GET('/groups'))).data,
});

export const groupAccountsQuery = (groupId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'accounts'],
        queryFn: async (): Promise<GroupAccount[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/accounts', {
                        params: { path: { group: groupId } },
                    }),
                )
            ).data,
    });

export const perimeterQuery = (groupId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'perimeter'],
        queryFn: async (): Promise<PerimeterMember[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/perimeter', {
                        params: { path: { group: groupId } },
                    }),
                )
            ).data,
    });

export const mappingQuery = (groupId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'mapping'],
        queryFn: async (): Promise<MappingVersion> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/account-mappings', {
                        params: { path: { group: groupId } },
                    }),
                )
            ).data,
    });

export const memberAccountsQuery = (groupId: string, companyId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'member-accounts', companyId],
        queryFn: async (): Promise<MemberAccount[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/member-accounts', {
                        params: {
                            path: { group: groupId },
                            query: { company_id: companyId },
                        },
                    }),
                )
            ).data,
    });

export const runsQuery = (groupId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'runs'],
        queryFn: async (): Promise<RunSummary[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/runs', {
                        params: { path: { group: groupId } },
                    }),
                )
            ).data,
    });

export const runQuery = (groupId: string, runId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'runs', runId],
        // A run never changes once it is made (CO-FR-09).
        staleTime: Infinity,
        queryFn: async (): Promise<Run> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/runs/{id}', {
                        params: { path: { group: groupId, id: runId } },
                    }),
                )
            ).data,
    });

export const adjustmentsQuery = (groupId: string, runId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'runs', runId, 'adjustments'],
        queryFn: async (): Promise<Adjustment[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/runs/{run}/adjustments', {
                        params: { path: { group: groupId, run: runId } },
                    }),
                )
            ).data,
    });

export async function proposeAdjustment(
    groupId: string,
    runId: string,
    body: AdjustmentInput,
): Promise<Adjustment> {
    return (
        await unwrap(
            api.POST('/groups/{group}/runs/{run}/adjustments', {
                params: { path: { group: groupId, run: runId } },
                body: body as unknown as AdjustmentRequest,
            }),
        )
    ).data;
}

export async function approveAdjustment(
    groupId: string,
    id: string,
): Promise<Adjustment> {
    return (
        await unwrap(
            api.POST('/groups/{group}/adjustments/{id}/approve', {
                params: { path: { group: groupId, id } },
            }),
        )
    ).data;
}

export async function rejectAdjustment(
    groupId: string,
    id: string,
    reason: string,
): Promise<Adjustment> {
    return (
        await unwrap(
            api.POST('/groups/{group}/adjustments/{id}/reject', {
                params: { path: { group: groupId, id } },
                body: { reason },
            }),
        )
    ).data;
}

export const rateTypesQuery = (groupId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'rate-types'],
        queryFn: async (): Promise<RateType[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/rate-types', {
                        params: { path: { group: groupId } },
                    }),
                )
            ).data,
    });

export const translationPolicyQuery = (groupId: string) =>
    queryOptions({
        queryKey: [...everything, groupId, 'translation-policy'],
        queryFn: async (): Promise<TranslationPolicy> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/translation-policy', {
                        params: { path: { group: groupId } },
                    }),
                )
            ).data,
    });

export const groupRatesQuery = (groupId: string, filters: RateFilters) =>
    queryOptions({
        queryKey: [...everything, groupId, 'rates', filters],
        queryFn: async (): Promise<GroupRate[]> =>
            (
                await unwrap(
                    api.GET('/groups/{group}/rates', {
                        params: {
                            path: { group: groupId },
                            query: {
                                'filter[rate_type_id]': filters.rate_type_id,
                                'filter[currency]': filters.currency,
                                'filter[include_superseded]':
                                    filters.include_superseded,
                                per_page: 200,
                            },
                        },
                    }),
                )
            ).data,
    });

export async function createRateType(
    groupId: string,
    body: { code: string; name: string },
): Promise<RateType> {
    return (
        await unwrap(
            api.POST('/groups/{group}/rate-types', {
                params: { path: { group: groupId } },
                body,
            }),
        )
    ).data;
}

export async function updateRateType(
    groupId: string,
    type: RateType,
    body: { name?: string; is_active?: boolean },
): Promise<RateType> {
    return (
        await unwrap(
            api.PATCH('/groups/{group}/rate-types/{id}', {
                params: { path: { group: groupId, id: type.id } },
                body: { lock_version: type.lock_version, ...body },
            }),
        )
    ).data;
}

/** The policy as a new version; `lockVersion` is the version that was read, 0 for none. */
export async function saveTranslationPolicy(
    groupId: string,
    body: components['schemas']['SaveTranslationPolicyRequest'],
) {
    return (
        await unwrap(
            api.PUT('/groups/{group}/translation-policy', {
                params: { path: { group: groupId } },
                body,
            }),
        )
    ).data;
}

export async function recordGroupRate(
    groupId: string,
    body: components['schemas']['RecordGroupRateRequest'],
): Promise<GroupRate> {
    return (
        await unwrap(
            api.POST('/groups/{group}/rates', {
                params: { path: { group: groupId } },
                body,
            }),
        )
    ).data;
}

export type CreateGroupInput = components['schemas']['CreateGroupRequest'];

export async function createGroup(body: CreateGroupInput) {
    return (await unwrap(api.POST('/groups', { body }))).data;
}

export type UpdateGroupInput = components['schemas']['UpdateGroupRequest'];

export async function updateGroup(groupId: string, body: UpdateGroupInput) {
    return (
        await unwrap(
            api.PATCH('/groups/{group}', {
                params: { path: { group: groupId } },
                body,
            }),
        )
    ).data;
}

export async function createGroupAccount(
    groupId: string,
    body: components['schemas']['CreateGroupAccountRequest'],
): Promise<GroupAccount> {
    return (
        await unwrap(
            api.POST('/groups/{group}/accounts', {
                params: { path: { group: groupId } },
                body,
            }),
        )
    ).data;
}

export async function updateGroupAccount(
    groupId: string,
    id: string,
    body: components['schemas']['UpdateGroupAccountRequest'],
): Promise<GroupAccount> {
    return (
        await unwrap(
            api.PATCH('/groups/{group}/accounts/{id}', {
                params: { path: { group: groupId, id } },
                body,
            }),
        )
    ).data;
}

export async function proposeMember(
    groupId: string,
    body: components['schemas']['ProposeMemberRequest'],
) {
    return (
        await unwrap(
            api.POST('/groups/{group}/perimeter', {
                params: { path: { group: groupId } },
                body,
            }),
        )
    ).data;
}

export async function approveMember(
    groupId: string,
    id: string,
): Promise<PerimeterMember> {
    return (
        await unwrap(
            api.POST('/groups/{group}/perimeter/{id}/approve', {
                params: { path: { group: groupId, id } },
            }),
        )
    ).data;
}

export async function endMember(
    groupId: string,
    id: string,
    effectiveTo: string,
): Promise<PerimeterMember> {
    return (
        await unwrap(
            api.POST('/groups/{group}/perimeter/{id}/end', {
                params: { path: { group: groupId, id } },
                body: { effective_to: effectiveTo },
            }),
        )
    ).data;
}

/** The whole mapping as it should be from now on; `lockVersion` is the version that was read, 0 for none. */
export async function saveMapping(
    groupId: string,
    lockVersion: number,
    mappings: {
        company_id: string;
        account_id: string;
        group_account_id: string;
    }[],
    effectiveDate: string,
): Promise<Pick<MappingVersion, 'version'>> {
    return (
        await unwrap(
            api.PUT('/groups/{group}/account-mappings', {
                params: { path: { group: groupId } },
                body: {
                    lock_version: lockVersion,
                    effective_date: effectiveDate,
                    mappings,
                },
            }),
        )
    ).data;
}

export async function createRun(
    groupId: string,
    from: string,
    to: string,
    formal = false,
): Promise<Run> {
    return (
        await unwrap(
            api.POST('/groups/{group}/runs', {
                params: { path: { group: groupId } },
                body: { from, to, formal },
            }),
        )
    ).data;
}

/** Downloads the result of a run as CSV, with the same permission as reading it (X-FR-02). */
export function exportRun(groupId: string, runId: string): Promise<void> {
    return downloadFile(
        `/api/v1/groups/${groupId}/runs/${runId}`,
        'text/csv',
        `management_rollup_${runId}.csv`,
    );
}
