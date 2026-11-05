import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';
import { downloadReport, type ExportFormat } from '@/lib/download';
import type { Coverage } from '@/modules/reporting/components/coverage-notice';
import {
    filterParams,
    type ReportFilter,
} from '@/modules/reporting/report-filter';

type Body =
    operations['reports.general-ledger']['responses'][200]['content']['application/json'];

/** The generated type leaves out the coverage fields of the report (RP-FR-13). */
export type GeneralLedger = Body['data'] & Coverage;
export type GeneralLedgerLine = GeneralLedger['lines'][number];

const params = (accountId: string, filter: ReportFilter) => {
    const query = filterParams(filter, 'range');

    query.set('account_id', accountId);

    return query;
};

/** The lines of one account with a running balance (RP-FR-06); one account per request, no pages. */
export const generalLedgerQuery = (
    companyId: string,
    accountId: string,
    filter: ReportFilter,
) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'reports',
            'general-ledger',
            accountId,
            filter,
        ],
        queryFn: async (): Promise<GeneralLedger> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/reports/general-ledger', {
                        params: {
                            path: { companyId },
                            query: {
                                from: filter.from,
                                to: filter.to,
                                account_id: accountId,
                            },
                        },
                        // The generated query type cannot express `dimensions[<key>]`.
                        querySerializer: () =>
                            params(accountId, filter).toString(),
                    }),
                )
            ).data as GeneralLedger,
    });

/** The same report as a file, under the same permission and filters (X-FR-02). */
export function exportGeneralLedger(
    companyId: string,
    accountId: string,
    filter: ReportFilter,
    code: string,
    format: ExportFormat = 'csv',
) {
    return downloadReport(
        `/api/v1/companies/${companyId}/reports/general-ledger?${params(accountId, filter)}`,
        format,
        `general-ledger_${code}_${filter.from}_${filter.to}`,
    );
}
