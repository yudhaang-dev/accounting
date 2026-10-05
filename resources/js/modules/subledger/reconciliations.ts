import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Reconciliation = components['schemas']['ReconciliationResource'];

export type ReconciliationStatus = 'balanced' | 'difference' | 'waived';

export type ReconciliationFilters = {
    asOf?: string;
    status?: ReconciliationStatus;
    page: number;
    perPage: number;
};

export type ReconciliationPage = {
    reconciliations: Reconciliation[];
    page: number;
    total: number;
};

export const reconciliationsQuery = (
    companyId: string,
    filters: ReconciliationFilters,
) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reconciliations', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<ReconciliationPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/reconciliations', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[as_of]': filters.asOf,
                            'filter[status]': filters.status,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                reconciliations: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

/** Compares every control and cash account with its subledger as of `asOf` (SL-FR-09). */
export async function runReconciliation(
    companyId: string,
    asOf: string,
): Promise<Reconciliation[]> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/reconciliations', {
                params: { path: { companyId } },
                body: { as_of: asOf },
            }),
        )
    ).data;
}

export async function waiveReconciliation(
    companyId: string,
    id: string,
    reason: string,
): Promise<Reconciliation> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/reconciliations/{id}/waive', {
                params: { path: { companyId, id } },
                body: { reason },
            }),
        )
    ).data;
}
