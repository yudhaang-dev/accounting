import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';
import {
    ApiError,
    apiErrorFrom,
    defaultHeaders,
    type ApiErrorBody,
} from '@/lib/http';

export type RateType = components['schemas']['RateTypeResource'];
export type ExchangeRate = components['schemas']['ExchangeRateResource'];
export type CreateRateTypeInput =
    components['schemas']['CreateRateTypeRequest'];
export type UpdateRateTypeInput =
    components['schemas']['UpdateRateTypeRequest'];
export type RecordRateInput =
    components['schemas']['RecordExchangeRateRequest'];

/** Up to 12 decimals: a rate is the functional currency per 1 unit, and small units need precision. */
export const RATE_DECIMALS = 12;

export const rateTypesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'rate-types'],
        queryFn: async (): Promise<RateType[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/rate-types', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export type RateFilters = {
    rateType?: string;
    currency?: string;
    includeSuperseded: boolean;
    page: number;
    perPage: number;
};

export type RatePage = {
    rates: ExchangeRate[];
    page: number;
    lastPage: number;
    total: number;
};

export const ratesQuery = (companyId: string, filters: RateFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'exchange-rates', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<RatePage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/exchange-rates', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[rate_type]': filters.rateType,
                            'filter[currency]': filters.currency,
                            'filter[include_superseded]':
                                filters.includeSuperseded || undefined,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                rates: body.data,
                page: body.meta.current_page,
                lastPage: body.meta.last_page,
                total: body.meta.total,
            };
        },
    });

export async function createRateType(
    companyId: string,
    body: CreateRateTypeInput,
): Promise<RateType> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/rate-types', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the key cannot change. */
export async function updateRateType(
    companyId: string,
    id: string,
    body: UpdateRateTypeInput,
): Promise<RateType> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/rate-types/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setRateTypeStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<RateType> {
    const path = `/companies/{companyId}/rate-types/{id}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, id } } }))
    ).data;
}

/**
 * Records a rate. A different rate for the same type, currency and date becomes a new version (a
 * correction); the same rate again changes nothing. `created` tells which of the two happened.
 */
export async function recordRate(
    companyId: string,
    body: RecordRateInput,
): Promise<{ rate: ExchangeRate; created: boolean }> {
    const result = await api
        .POST('/companies/{companyId}/exchange-rates', {
            params: { path: { companyId } },
            body,
        })
        .catch(() => undefined);

    if (result === undefined) {
        throw new ApiError(0, 'Network error', 'NETWORK');
    }

    if (result.error !== undefined || result.data === undefined) {
        const error =
            typeof result.error === 'object' && result.error !== null
                ? (result.error as Partial<ApiErrorBody>)
                : {};

        throw apiErrorFrom(result.response, error);
    }

    return { rate: result.data.data, created: result.response.status === 201 };
}

/**
 * Imports a CSV (columns `rate_type`, `currency`, `effective_date`, `rate`). All or nothing: the
 * API refuses the whole file and names every bad row, as `rows.<line>.<field>` errors.
 */
export async function importRates(
    companyId: string,
    csv: string,
): Promise<{ created: number; unchanged: number }> {
    let response: Response;

    try {
        response = await fetch(
            `/api/v1/companies/${companyId}/exchange-rates/import`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    ...defaultHeaders('POST'),
                    'Content-Type': 'text/csv',
                },
                body: csv,
            },
        );
    } catch {
        throw new ApiError(0, 'Network error', 'NETWORK');
    }

    const body = (await response.json().catch(() => ({}))) as {
        data?: { created: number; unchanged: number };
    } & Partial<ApiErrorBody>;

    if (!response.ok || body.data === undefined) {
        throw apiErrorFrom(response, body);
    }

    return body.data;
}
