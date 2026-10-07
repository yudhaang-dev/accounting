import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';

type Body =
    operations['reporting.account-role-mappings.index']['responses'][200]['content']['application/json'];

export type CashEquivalentsMapping = Body['data'][number];

const KEY = ['reporting', 'account-role-mappings'] as const;

/** The mapping of the `cash_equivalents` role in force: empty and version 0 while it is not mapped (RP-FR-03). */
export const cashEquivalentsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, ...KEY],
        queryFn: async (): Promise<CashEquivalentsMapping> => {
            const rows = (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/reporting/account-role-mappings',
                        { params: { path: { companyId } } },
                    ),
                )
            ).data;
            const mapping = rows.find((row) => row.key === 'cash_equivalents');

            return (
                mapping ?? {
                    key: 'cash_equivalents',
                    account_ids: [],
                    effective_date: null,
                    problems: {},
                    lock_version: 0,
                }
            );
        },
    });

export const cashEquivalentsKey = (companyId: string) =>
    ['companies', companyId, ...KEY] as const;

/** Maps the role to these accounts as a new version; `lockVersion` is the one that was read. */
export async function saveCashEquivalents(
    companyId: string,
    body: {
        lock_version: number;
        account_ids: string[];
        effective_date?: string | null;
    },
) {
    return (
        await unwrap(
            api.PUT(
                '/companies/{companyId}/reporting/account-role-mappings/cash_equivalents',
                { params: { path: { companyId } }, body },
            ),
        )
    ).data;
}
