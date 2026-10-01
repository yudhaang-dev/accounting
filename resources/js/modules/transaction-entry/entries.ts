import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Entry = components['schemas']['EntryResource'];
export type EntryStatus = components['schemas']['EntryStatus'];
export type EntryInput = components['schemas']['EntryRequest'];
export type UpdateEntryInput = components['schemas']['UpdateEntryRequest'];
export type TransactionType = components['schemas']['TransactionTypeResource'];

export const ENTRY_STATUSES: EntryStatus[] = [
    'draft',
    'submitted',
    'approved',
    'posted',
    'rejected',
    'cancelled',
];

export type EntryFilters = {
    status?: EntryStatus;
    from?: string;
    to?: string;
    page: number;
};

export type EntryPage = {
    entries: Entry[];
    page: number;
    lastPage: number;
    total: number;
};

export const entriesQuery = (companyId: string, filters: EntryFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<EntryPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/entries', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[status]': filters.status,
                            'filter[from]': filters.from || undefined,
                            'filter[to]': filters.to || undefined,
                            page: filters.page,
                        },
                    },
                }),
            );

            return {
                entries: body.data,
                page: body.meta.current_page,
                lastPage: body.meta.last_page,
                total: body.meta.total,
            };
        },
    });

/** One entry with its approval trail. */
export const entryQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', id],
        queryFn: async (): Promise<Entry> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/entries/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

export const transactionTypesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-types'],
        queryFn: async (): Promise<TransactionType[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/transaction-types', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

/** Accounts an entry line may use: active ones, leaf accounts only (parents take no postings). */
export type LineAccount = { id: string; code: string; name: string };

export const lineAccountsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', 'accounts'],
        staleTime: 60_000,
        queryFn: async (): Promise<LineAccount[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/accounts', {
                        params: {
                            path: { companyId },
                            query: {
                                'filter[status]': 'active',
                                per_page: 200,
                            },
                        },
                    }),
                )
            ).data
                .filter((account) => account.is_leaf)
                .map(({ id, code, name }) => ({ id, code, name })),
    });

/** Every account by id, whatever its status: a posted entry may use an account closed since. */
export const accountNamesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', 'account-names'],
        staleTime: 60_000,
        queryFn: async (): Promise<Record<string, LineAccount>> =>
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
                ).data.map(({ id, code, name }) => [id, { id, code, name }]),
            ),
    });

export async function createEntry(
    companyId: string,
    body: EntryInput,
): Promise<Entry> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/entries', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** Only a draft can be replaced; `lock_version` is required. */
export async function updateEntry(
    companyId: string,
    id: string,
    body: UpdateEntryInput,
): Promise<Entry> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/entries/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export type EntryAction =
    | 'submit'
    | 'approve'
    | 'post'
    | 'revise'
    | 'reject'
    | 'cancel';

/** Lifecycle step of an entry (TE-FR-07). `reject` needs a reason; `cancel` may carry one. */
export async function runEntryAction(
    companyId: string,
    id: string,
    action: EntryAction,
    reason?: string,
): Promise<Entry> {
    const params = { path: { companyId, id } };

    switch (action) {
        case 'submit':
            return (
                await unwrap(
                    api.POST('/companies/{companyId}/entries/{id}/submit', {
                        params,
                    }),
                )
            ).data;
        case 'approve':
            return (
                await unwrap(
                    api.POST('/companies/{companyId}/entries/{id}/approve', {
                        params,
                    }),
                )
            ).data;
        case 'post':
            return (
                await unwrap(
                    api.POST('/companies/{companyId}/entries/{id}/post', {
                        params,
                    }),
                )
            ).data;
        case 'revise':
            return (
                await unwrap(
                    api.POST('/companies/{companyId}/entries/{id}/revise', {
                        params,
                    }),
                )
            ).data;
        case 'reject':
            return (
                await unwrap(
                    api.POST('/companies/{companyId}/entries/{id}/reject', {
                        params,
                        body: { reason },
                    }),
                )
            ).data;
        case 'cancel':
            return (
                await unwrap(
                    api.POST('/companies/{companyId}/entries/{id}/cancel', {
                        params,
                        body: { reason: reason || null },
                    }),
                )
            ).data;
    }
}

export async function createTransactionType(
    companyId: string,
    body: { key: string; label: string },
): Promise<TransactionType> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/transaction-types', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}
