import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type Currency = components['schemas']['CurrencyResource'];
export type IsoCurrency = components['schemas']['IsoCurrencyResource'];
export type CurrencyRounding = components['schemas']['CurrencyRounding'];
export type ActivateCurrencyInput =
    components['schemas']['ActivateCurrencyRequest'];
export type UpdateCurrencyInput =
    components['schemas']['UpdateCurrencyRequest'];

export const ROUNDING_MODES: CurrencyRounding[] = [
    'half_up',
    'half_even',
    'up',
    'down',
];

/** Decimal places a currency may be kept in (the ledger stores up to six). */
export const MAX_SCALE = 6;

/** The currencies the company activated, functional currency first. */
export const currenciesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'currencies'],
        queryFn: async (): Promise<Currency[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/currencies', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

/** The ISO 4217 reference a currency is activated from. It never changes, so it is kept for a while. */
export const isoCurrenciesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'iso-currencies'],
        staleTime: 10 * 60_000,
        queryFn: async (): Promise<IsoCurrency[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/iso-currencies', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export async function activateCurrency(
    companyId: string,
    body: ActivateCurrencyInput,
): Promise<Currency> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/currencies', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the scale of the functional currency only changes during setup. */
export async function updateCurrency(
    companyId: string,
    code: string,
    body: UpdateCurrencyInput,
): Promise<Currency> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/currencies/{code}', {
                params: { path: { companyId, code } },
                body,
            }),
        )
    ).data;
}

export async function setCurrencyStatus(
    companyId: string,
    code: string,
    action: 'deactivate' | 'reactivate',
): Promise<Currency> {
    const path = `/companies/{companyId}/currencies/{code}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, code } } }))
    ).data;
}
