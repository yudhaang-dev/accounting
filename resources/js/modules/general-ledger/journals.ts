import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Journal = components['schemas']['JournalResource'];

/** A posted journal with its lines; journals are immutable, so there is nothing to refresh. */
export const journalQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'journals', id],
        staleTime: Infinity,
        queryFn: async (): Promise<Journal> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/journals/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

export type JournalFilters = {
    account_id?: string;
    from?: string;
    to?: string;
    page: number;
    perPage: number;
};

export type JournalPage = {
    journals: Journal[];
    page: number;
    lastPage: number;
    total: number;
};

/** Posted journals, newest first. With `account_id` it is the drill-down of a report row. */
export const journalsQuery = (companyId: string, filters: JournalFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'journals', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<JournalPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/journals', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[account_id]': filters.account_id,
                            'filter[from]': filters.from || undefined,
                            'filter[to]': filters.to || undefined,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                journals: body.data,
                page: body.meta.current_page,
                lastPage: body.meta.last_page,
                total: body.meta.total,
            };
        },
    });

/** Every account by id, whatever its status, to name the lines of a journal. */
export const accountNamesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'journals', 'account-names'],
        staleTime: 60_000,
        queryFn: async (): Promise<
            Record<string, { code: string; name: string }>
        > =>
            Object.fromEntries(
                (
                    await unwrap(
                        api.GET('/companies/{companyId}/accounts', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200 },
                            },
                        }),
                    )
                ).data.map(({ id, code, name }) => [id, { code, name }]),
            ),
    });
