import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { isApiError } from '@/lib/http';

export type FiscalCalendar = components['schemas']['FiscalCalendarResource'];
export type FiscalYear = components['schemas']['FiscalYearResource'];

/** The calendar of the company, or `null` while none has been set up (the API answers 404). */
export const fiscalCalendarQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'fiscal-calendar'],
        queryFn: async (): Promise<FiscalCalendar | null> => {
            try {
                return (
                    await unwrap(
                        api.GET('/companies/{companyId}/fiscal-calendar', {
                            params: { path: { companyId } },
                        }),
                    )
                ).data;
            } catch (error) {
                if (isApiError(error) && error.status === 404) {
                    return null;
                }

                throw error;
            }
        },
    });

export const fiscalYearsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'fiscal-years'],
        queryFn: async (): Promise<FiscalYear[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/fiscal-years', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

export async function createFiscalCalendar(
    companyId: string,
    input: { start_month: number },
) {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/fiscal-calendar', {
                params: { path: { companyId } },
                body: { pattern: 'monthly', ...input },
            }),
        )
    ).data;
}

/** The first year needs its `start_date` (the 1st of the calendar's start month). */
export async function createFiscalYears(
    companyId: string,
    input: { start_date: string; count: number },
) {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/fiscal-years', {
                params: { path: { companyId } },
                body: input,
            }),
        )
    ).data;
}
