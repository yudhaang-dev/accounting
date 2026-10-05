import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components, operations } from '@/api/schema';
import { downloadFile } from '@/lib/download';

type Body =
    operations['reports.aging']['responses'][200]['content']['application/json'];

export type AgingReport = Body['data'];
export type AgingBucket = AgingReport['parameters']['buckets'][number];
export type AgingParty = AgingReport['parties'][number];
export type AgingBasis = 'due_date' | 'document_date';
export type ControlRole = components['schemas']['ControlRole'];

export type AgingFilters = {
    asOf: string;
    controlRole: ControlRole;
    partyId?: string;
    basis?: AgingBasis;
};

export const agingQuery = (companyId: string, filters: AgingFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reports', 'aging', filters],
        queryFn: async (): Promise<AgingReport> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/reports/aging', {
                        params: {
                            path: { companyId },
                            query: {
                                as_of: filters.asOf,
                                control_role: filters.controlRole,
                                party_id: filters.partyId,
                                basis: filters.basis,
                            },
                        },
                    }),
                )
            ).data,
    });

/** The same report as CSV, under the same permission and filters (X-FR-02). */
export function exportAging(companyId: string, filters: AgingFilters) {
    const query = new URLSearchParams({
        as_of: filters.asOf,
        control_role: filters.controlRole,
    });

    if (filters.partyId) {
        query.set('party_id', filters.partyId);
    }

    if (filters.basis) {
        query.set('basis', filters.basis);
    }

    return downloadFile(
        `/api/v1/companies/${companyId}/reports/aging?${query.toString()}`,
        'text/csv',
        `aging_${filters.controlRole}_${filters.asOf}.csv`,
    );
}

export type AgingPolicy = {
    basis: AgingBasis;
    buckets: number[];
    lock_version: number;
};

export const agingPolicyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'aging-policy'],
        queryFn: async (): Promise<AgingPolicy> => {
            const { data } = await unwrap(
                api.GET('/companies/{companyId}/aging-policy', {
                    params: { path: { companyId } },
                }),
            );

            return {
                basis: data.basis as AgingBasis,
                buckets: data.buckets as number[],
                lock_version: Number(data.lock_version),
            };
        },
    });

export async function updateAgingPolicy(
    companyId: string,
    body: AgingPolicy,
): Promise<void> {
    await unwrap(
        api.PUT('/companies/{companyId}/aging-policy', {
            params: { path: { companyId } },
            body,
        }),
    );
}
