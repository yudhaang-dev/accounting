import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Account = components['schemas']['AccountResource'];
export type AccountClass = components['schemas']['AccountClass'];
export type AccountStatus = components['schemas']['AccountStatus'];
export type CreateAccountInput = components['schemas']['CreateAccountRequest'];
export type UpdateAccountInput = components['schemas']['UpdateAccountRequest'];

export const ACCOUNT_CLASSES: AccountClass[] = [
    'asset',
    'liability',
    'equity',
    'income',
    'expense',
];

export type AccountFilters = {
    class?: AccountClass;
    status?: AccountStatus;
    search?: string;
    page: number;
};

export type AccountPage = {
    accounts: Account[];
    page: number;
    lastPage: number;
    total: number;
};

export const accountsQuery = (companyId: string, filters: AccountFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'accounts', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<AccountPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/accounts', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[class]': filters.class,
                            'filter[status]': filters.status,
                            'filter[search]': filters.search || undefined,
                            page: filters.page,
                        },
                    },
                }),
            );

            return {
                accounts: body.data,
                page: body.meta.current_page,
                lastPage: body.meta.last_page,
                total: body.meta.total,
            };
        },
    });

/** Every active account of a class, to choose a parent from (a chart stays well under 200). */
export const parentOptionsQuery = (
    companyId: string,
    accountClass: AccountClass,
) =>
    queryOptions({
        queryKey: ['companies', companyId, 'accounts', 'parents', accountClass],
        queryFn: async (): Promise<Account[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/accounts', {
                        params: {
                            path: { companyId },
                            query: {
                                'filter[class]': accountClass,
                                'filter[status]': 'active',
                                per_page: 200,
                            },
                        },
                    }),
                )
            ).data,
    });

export async function createAccount(
    companyId: string,
    body: CreateAccountInput,
): Promise<Account> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/accounts', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the class cannot change (a different class is a different account). */
export async function updateAccount(
    companyId: string,
    id: string,
    body: UpdateAccountInput,
): Promise<Account> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/accounts/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setAccountStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<Account> {
    const path = `/companies/{companyId}/accounts/{id}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, id } } }))
    ).data;
}
