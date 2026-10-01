import { queryOptions } from '@tanstack/react-query';
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
