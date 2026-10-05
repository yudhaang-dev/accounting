import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import type { Account } from '@/modules/general-ledger/accounts';

export type CashAccount = components['schemas']['CashAccountResource'];
export type CashTransaction = components['schemas']['CashTransactionResource'];
export type CreateCashAccountInput =
    components['schemas']['CreateCashAccountRequest'];
export type UpdateCashAccountInput =
    components['schemas']['UpdateCashAccountRequest'];

export type CashKind = 'cash' | 'bank';

/** Every cash account of the company, for the pickers and the names a transaction row shows. */
export const cashAccountsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'cash-accounts', 'all'],
        queryFn: async (): Promise<CashAccount[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/cash-accounts', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200 },
                        },
                    }),
                )
            ).data,
    });

export async function createCashAccount(
    companyId: string,
    body: CreateCashAccountInput,
): Promise<CashAccount> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/cash-accounts', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the kind, currency and ledger account never change. */
export async function updateCashAccount(
    companyId: string,
    id: string,
    body: UpdateCashAccountInput,
): Promise<CashAccount> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/cash-accounts/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setCashAccountStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<CashAccount> {
    return (
        await unwrap(
            api.POST(
                `/companies/{companyId}/cash-accounts/{id}/${action}` as const,
                { params: { path: { companyId, id } } },
            ),
        )
    ).data;
}

/**
 * Whether an account can hold a cash account of `currency` (SL-FR-03): an active asset leaf that
 * accepts the `subledger` source only and the currency.
 */
export function eligibleForCash(
    account: Account,
    currency: string,
    functionalCurrency: string,
): boolean {
    const restriction = account.currency_restriction;

    return (
        account.status === 'active' &&
        account.is_leaf &&
        account.class === 'asset' &&
        account.allowed_sources.length === 1 &&
        account.allowed_sources[0] === 'subledger' &&
        (restriction === 'any' ||
            (restriction === 'functional_only' &&
                currency === functionalCurrency) ||
            (restriction === 'specific' &&
                account.restricted_currency === currency))
    );
}

export type CashTransactionFilters = {
    cashAccountId?: string;
    cleared?: boolean;
    dateFrom: string;
    dateTo: string;
    page: number;
    perPage: number;
};

export type CashTransactionPage = {
    transactions: CashTransaction[];
    page: number;
    total: number;
};

export const cashTransactionsQuery = (
    companyId: string,
    filters: CashTransactionFilters,
) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'cash-transactions',
            'list',
            filters,
        ],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<CashTransactionPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/cash-transactions', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[cash_account_id]': filters.cashAccountId,
                            // Laravel's `boolean` rule reads 1 and 0, not the "false" the client would write.
                            'filter[cleared]':
                                filters.cleared === undefined
                                    ? undefined
                                    : (Number(
                                          filters.cleared,
                                      ) as unknown as boolean),
                            'filter[date_from]': filters.dateFrom || undefined,
                            'filter[date_to]': filters.dateTo || undefined,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                transactions: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

/** Marks a transaction cleared on `clearedOn` (today when empty), or takes the mark off. */
export async function clearCashTransaction(
    companyId: string,
    id: string,
    clearedOn: string | null,
): Promise<CashTransaction> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/cash-transactions/{id}/clear', {
                params: { path: { companyId, id } },
                body: { cleared_on: clearedOn },
            }),
        )
    ).data;
}

export async function unclearCashTransaction(
    companyId: string,
    id: string,
): Promise<CashTransaction> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/cash-transactions/{id}/unclear', {
                params: { path: { companyId, id } },
            }),
        )
    ).data;
}
