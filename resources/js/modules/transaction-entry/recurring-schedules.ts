import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type Schedule = components['schemas']['RecurringScheduleResource'];
export type ScheduleInput = components['schemas']['RecurringScheduleRequest'];
export type UpdateScheduleInput =
    components['schemas']['UpdateRecurringScheduleRequest'];
export type Frequency = ScheduleInput['frequency'];
export type ScheduleStatus = 'active' | 'paused' | 'completed';

export const FREQUENCIES: Frequency[] = [
    'daily',
    'weekly',
    'monthly',
    'quarterly',
    'yearly',
];

export const SCHEDULE_STATUSES: ScheduleStatus[] = [
    'active',
    'paused',
    'completed',
];

/** Every schedule of the company, in the order the API gives (next run first); narrowed in the browser. */
export const schedulesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'recurring-schedules', 'list'],
        queryFn: async (): Promise<Schedule[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/recurring-schedules', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export const scheduleQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'recurring-schedules', id],
        queryFn: async (): Promise<Schedule> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/recurring-schedules/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

export async function createSchedule(
    companyId: string,
    body: ScheduleInput,
): Promise<Schedule> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/recurring-schedules', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** The template, end date, where entries are left and the status are replaced; `lock_version` is required. */
export async function updateSchedule(
    companyId: string,
    id: string,
    body: UpdateScheduleInput,
): Promise<Schedule> {
    return (
        await unwrap(
            api.PUT('/companies/{companyId}/recurring-schedules/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

/** Gives up the date a schedule is stuck at, or waiting at, and goes on to the next. */
export async function skipSchedule(
    companyId: string,
    id: string,
): Promise<Schedule> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/recurring-schedules/{id}/skip', {
                params: { path: { companyId, id } },
            }),
        )
    ).data;
}
