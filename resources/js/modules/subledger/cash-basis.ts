import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';
import { downloadFile } from '@/lib/download';

type Body =
    operations['reports.cash-basis-expenses']['responses'][200]['content']['application/json'];

export type CashBasisExpenses = Body['data'];

export type CashBasisFilters = { from: string; to: string };

export const cashBasisQuery = (companyId: string, filters: CashBasisFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reports', 'cash-basis', filters],
        queryFn: async (): Promise<CashBasisExpenses> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/reports/cash-basis-expenses',
                        {
                            params: {
                                path: { companyId },
                                query: filters,
                            },
                        },
                    ),
                )
            ).data,
    });

/** The same report as CSV, under the same permission and range (X-FR-02). */
export function exportCashBasis(companyId: string, filters: CashBasisFilters) {
    const query = new URLSearchParams(filters);

    return downloadFile(
        `/api/v1/companies/${companyId}/reports/cash-basis-expenses?${query.toString()}`,
        'text/csv',
        `cash_basis_expenses_${filters.from}_${filters.to}.csv`,
    );
}
