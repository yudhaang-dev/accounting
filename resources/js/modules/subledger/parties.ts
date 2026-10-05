import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Party = components['schemas']['PartyResource'];
export type CreatePartyInput = components['schemas']['CreatePartyRequest'];
export type UpdatePartyInput = components['schemas']['UpdatePartyRequest'];

export type PartyFilters = {
    status?: 'active' | 'inactive';
    role?: 'customer' | 'supplier';
    search: string;
    page: number;
    perPage: number;
};

export type PartyPage = {
    parties: Party[];
    page: number;
    total: number;
};

export const partiesQuery = (companyId: string, filters: PartyFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'parties', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<PartyPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/parties', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[status]': filters.status,
                            'filter[role]': filters.role,
                            'filter[search]': filters.search || undefined,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                parties: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

export async function createParty(
    companyId: string,
    body: CreatePartyInput,
): Promise<Party> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/parties', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required. References are only added: the API never removes one. */
export async function updateParty(
    companyId: string,
    id: string,
    body: UpdatePartyInput,
): Promise<Party> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/parties/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setPartyStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<Party> {
    const path = `/companies/{companyId}/parties/{id}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, id } } }))
    ).data;
}
