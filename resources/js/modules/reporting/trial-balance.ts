import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';
import { downloadFile } from '@/lib/download';

type Body =
    operations['reports.trial-balance']['responses'][200]['content']['application/json'];

export type TrialBalance = Body['data'];
export type TrialBalanceRow = TrialBalance['rows'][number];

export type Range = { from: string; to: string };

export const trialBalanceQuery = (companyId: string, range: Range) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reports', 'trial-balance', range],
        queryFn: async (): Promise<TrialBalance> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/reports/trial-balance', {
                        params: {
                            path: { companyId },
                            query: { from: range.from, to: range.to },
                        },
                    }),
                )
            ).data,
    });

/** The same report as CSV, under the same permission and filters (X-FR-02). */
export function exportTrialBalance(companyId: string, range: Range) {
    const query = new URLSearchParams(range).toString();

    return downloadFile(
        `/api/v1/companies/${companyId}/reports/trial-balance?${query}`,
        'text/csv',
        `trial-balance_${range.from}_${range.to}.csv`,
    );
}
