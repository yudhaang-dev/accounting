import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';

type Body =
    operations['reports.party-balances']['responses'][200]['content']['application/json'];

export type PartyBalances = Body['data'];
export type PartyBalance = PartyBalances['parties'][number];

/** What each party owes and is owed on a date, in the functional currency; parties with nothing outstanding are absent. */
export const partyBalancesQuery = (
    companyId: string,
    asOf: string,
    enabled = true,
) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reports', 'party-balances', asOf],
        enabled,
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<PartyBalances> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/reports/party-balances', {
                        params: {
                            path: { companyId },
                            query: { as_of: asOf },
                        },
                    }),
                )
            ).data,
    });
