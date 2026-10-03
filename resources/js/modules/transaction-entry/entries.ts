import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type Entry = components['schemas']['EntryResource'];
export type EntryStatus = components['schemas']['EntryStatus'];
export type EntryInput = components['schemas']['EntryRequest'];
export type UpdateEntryInput = components['schemas']['UpdateEntryRequest'];
export {
    transactionTypesQuery,
    type TransactionType,
} from '@/modules/transaction-entry/transaction-types';

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
    perPage: number;
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
                            per_page: filters.perPage,
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

/** What a line may use for one dimension type: its active values, with their validity. */
export type LineDimensionType = {
    key: string;
    label: string;
    values: {
        id: string;
        code: string;
        name: string;
        valid_from: string | null;
        valid_to: string | null;
    }[];
};

/**
 * The dimension types an entry line can carry, each with its active values (MD-FR-04). A type with no
 * active value is left out, so the form never shows a picker with nothing to pick.
 */
export const lineDimensionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', 'dimensions'],
        staleTime: 60_000,
        queryFn: async (): Promise<LineDimensionType[]> => {
            const [types, values] = await Promise.all([
                fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/dimension-types', {
                            params: {
                                path: { companyId },
                                query: {
                                    'filter[status]': 'active',
                                    per_page: 200,
                                    page,
                                },
                            },
                        }),
                    ),
                ),
                fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/dimension-values', {
                            params: {
                                path: { companyId },
                                query: {
                                    'filter[status]': 'active',
                                    per_page: 200,
                                    page,
                                },
                            },
                        }),
                    ),
                ),
            ]);

            return types
                .map((type) => ({
                    key: type.key,
                    label: type.label,
                    values: values
                        .filter((value) => value.dimension_type_id === type.id)
                        .map(({ id, code, name, valid_from, valid_to }) => ({
                            id,
                            code,
                            name,
                            valid_from,
                            valid_to,
                        })),
                }))
                .filter((type) => type.values.length > 0);
        },
    });

/** Names for the dimensions on posted lines, whatever the status of the type or value since. */
export type DimensionNames = {
    types: Record<string, string>;
    values: Record<string, { code: string; name: string }>;
};

export const dimensionNamesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'entries', 'dimension-names'],
        staleTime: 60_000,
        queryFn: async (): Promise<DimensionNames> => {
            const [types, values] = await Promise.all([
                fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/dimension-types', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                ),
                fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/dimension-values', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                ),
            ]);

            return {
                types: Object.fromEntries(types.map((t) => [t.key, t.label])),
                values: Object.fromEntries(
                    values.map(({ id, code, name }) => [id, { code, name }]),
                ),
            };
        },
    });

/**
 * The API takes `dimensions` of a line as an object (type key to value id), while its generated type
 * calls it a list; this is the one place that bridges the two. Empty choices are left out.
 */
export function dimensionsBody(
    dimensions: Record<string, string>,
): (string | null)[] {
    return Object.fromEntries(
        Object.entries(dimensions).filter(([, value]) => value !== ''),
    ) as unknown as (string | null)[];
}

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
