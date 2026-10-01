import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Period = components['schemas']['PeriodResource'];

/** Periods of one fiscal year in date order (a year has at most 13). */
export const periodsQuery = (companyId: string, fiscalYearId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'periods', fiscalYearId],
        queryFn: async (): Promise<Period[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/periods', {
                        params: {
                            path: { companyId },
                            query: {
                                'filter[fiscal_year_id]': fiscalYearId,
                                per_page: 100,
                            },
                        },
                    }),
                )
            ).data,
    });

export async function closePeriod(
    companyId: string,
    id: string,
): Promise<Period> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/periods/{id}/close', {
                params: { path: { companyId, id } },
            }),
        )
    ).data;
}

/** A reason is required; it is audited together with the actor (GL-FR-04). */
export async function reopenPeriod(
    companyId: string,
    id: string,
    reason: string,
): Promise<Period> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/periods/{id}/reopen', {
                params: { path: { companyId, id } },
                body: { reason },
            }),
        )
    ).data;
}
