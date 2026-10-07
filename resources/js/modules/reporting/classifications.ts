import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components, operations } from '@/api/schema';
import type { Coverage } from '@/modules/reporting/components/coverage-notice';
import {
    filterParams,
    type ReportFilter,
} from '@/modules/reporting/report-filter';

export type MovementClassification =
    components['schemas']['MovementClassificationResource'];

type CashFlowBody =
    operations['movement-classifications.cash-flow']['responses'][200]['content']['application/json']['data'];
type EquityBody =
    operations['movement-classifications.equity-changes']['responses'][200]['content']['application/json']['data'];

/** The generated types leave out the coverage fields of the reports (RP-FR-13). */
export type CashFlow = CashFlowBody & Coverage;
export type EquityChanges = EquityBody & Coverage;
export type Unclassified = CashFlow['unclassified'][number];

export const classificationsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'movement-classifications', 'list'],
        queryFn: async (): Promise<MovementClassification[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/movement-classifications', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200 },
                        },
                    }),
                )
            ).data,
    });

export const classificationQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'movement-classifications', id],
        queryFn: async (): Promise<MovementClassification> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/movement-classifications/{id}',
                        { params: { path: { companyId, id } } },
                    ),
                )
            ).data,
    });

const query = (filter: ReportFilter) => ({
    from: filter.from,
    to: filter.to,
});

/** Direct cash flow of a range, read through the classification (RP-FR-09). */
export const cashFlowQuery = (
    companyId: string,
    id: string,
    filter: ReportFilter,
) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'movement-classifications',
            id,
            'cash-flow',
            filter,
        ],
        queryFn: async (): Promise<CashFlow> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/movement-classifications/{id}/cash-flow',
                        {
                            params: {
                                path: { companyId, id },
                                query: query(filter),
                            },
                            // The generated query type cannot express `dimensions[<key>]`.
                            querySerializer: () =>
                                filterParams(filter, 'range').toString(),
                        },
                    ),
                )
            ).data as CashFlow,
    });

/** Changes in equity per component over a range (RP-FR-10). */
export const equityChangesQuery = (
    companyId: string,
    id: string,
    filter: ReportFilter,
) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'movement-classifications',
            id,
            'equity-changes',
            filter,
        ],
        queryFn: async (): Promise<EquityChanges> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/movement-classifications/{id}/equity-changes',
                        {
                            params: {
                                path: { companyId, id },
                                query: query(filter),
                            },
                            querySerializer: () =>
                                filterParams(filter, 'range').toString(),
                        },
                    ),
                )
            ).data as EquityChanges,
    });
