import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';
import { downloadFile } from '@/lib/download';
import { filterParams, type ReportFilter } from '@/modules/reporting/report-filter';

type Body =
    operations['reports.trial-balance']['responses'][200]['content']['application/json'];

export type TrialBalance = Body['data'];
export type TrialBalanceRow = TrialBalance['rows'][number];

export const trialBalanceQuery = (companyId: string, filter: ReportFilter) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reports', 'trial-balance', filter],
        queryFn: async (): Promise<TrialBalance> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/reports/trial-balance', {
                        params: {
                            path: { companyId },
                            query: {
                                from: filter.from,
                                to: filter.to,
                                account_id: null,
                            },
                        },
                        // The generated query type cannot express `dimensions[<key>]`.
                        querySerializer: () =>
                            filterParams(filter, 'range').toString(),
                    }),
                )
            ).data,
    });

/** The same report as CSV, under the same permission and filters (X-FR-02). */
export function exportTrialBalance(companyId: string, filter: ReportFilter) {
    return downloadFile(
        `/api/v1/companies/${companyId}/reports/trial-balance?${filterParams(filter, 'range')}`,
        'text/csv',
        `trial-balance_${filter.from}_${filter.to}.csv`,
    );
}
