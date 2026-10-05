import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type Allocation = components['schemas']['AllocationResource'];
export type AllocateInput = components['schemas']['AllocateRequest'];

export type AllocationStatus = 'active' | 'cancelled';

export type AllocationFilters = {
    openItemId?: string;
    status?: AllocationStatus;
    page: number;
    perPage: number;
};

export type AllocationPage = {
    allocations: Allocation[];
    page: number;
    total: number;
};

export const allocationsQuery = (
    companyId: string,
    filters: AllocationFilters,
) =>
    queryOptions({
        queryKey: ['companies', companyId, 'allocations', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<AllocationPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/allocations', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[open_item_id]': filters.openItemId,
                            'filter[status]': filters.status,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                allocations: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

/** Every open item, to name the two sides of an allocation by their document. */
export const openItemNamesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'open-items', 'names'],
        queryFn: async () =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/open-items', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export async function allocate(
    companyId: string,
    body: AllocateInput,
): Promise<Allocation> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/allocations', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

export async function cancelAllocation(
    companyId: string,
    id: string,
    reason: string,
): Promise<Allocation> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/allocations/{id}/cancel', {
                params: { path: { companyId, id } },
                body: { reason },
            }),
        )
    ).data;
}
