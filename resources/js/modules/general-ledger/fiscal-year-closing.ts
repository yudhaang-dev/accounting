import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type ClosingRun = components['schemas']['ClosingRunResource'];

/**
 * Where a fiscal year stands in its closing (GL-FR-12). The OpenAPI document types `closing` loosely
 * (a limit of the generator); it is the closing in force, or null while the year is open.
 */
export type ClosingStatus = Omit<
    components['schemas']['FiscalYearClosingStatusResource'],
    'closing'
> & { closing: ClosingRun | null };

export const closingStatusQuery = (companyId: string, fiscalYearId: string) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'fiscal-years',
            fiscalYearId,
            'closing',
        ],
        queryFn: async (): Promise<ClosingStatus> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/fiscal-years/{id}/closing',
                        {
                            params: { path: { companyId, id: fiscalYearId } },
                        },
                    ),
                )
            ).data as unknown as ClosingStatus,
    });

// The generated type of the answer to closing is a bare string (the controller returns a response
// object, which the generator cannot read); it is `{ data: closing }`.
export async function closeFiscalYear(
    companyId: string,
    fiscalYearId: string,
): Promise<ClosingRun> {
    const answer = (await unwrap(
        api.POST('/companies/{companyId}/fiscal-years/{id}/close', {
            params: { path: { companyId, id: fiscalYearId } },
        }),
    )) as unknown as { data: ClosingRun };

    return answer.data;
}

/** A reason is required; it is kept on the closing and in the audit log (GL-FR-14). */
export async function reopenFiscalYear(
    companyId: string,
    fiscalYearId: string,
    reason: string,
): Promise<ClosingRun> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/fiscal-years/{id}/reopen', {
                params: { path: { companyId, id: fiscalYearId } },
                body: { reason },
            }),
        )
    ).data;
}
