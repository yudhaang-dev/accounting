import { api, unwrap } from '@/api/client';
import type { paths } from '@/api/schema';

type Answer =
    paths['/companies/{companyId}/periods/{id}/open-item-revaluation']['post']['responses'][200]['content']['application/json'];

export type OpenItemRevaluationRun = Answer['data'];
export type OpenItemAdjustment = OpenItemRevaluationRun['adjustments'][number];

/**
 * Revalues the foreign currency open items as of the last day of the period, at the closing rate of
 * the ledger policy (SL-FR-07). `id` is null when nothing needed adjusting; running again only posts
 * what changed since.
 */
export async function runOpenItemRevaluation(
    companyId: string,
    periodId: string,
): Promise<OpenItemRevaluationRun> {
    return (
        await unwrap(
            api.POST(
                '/companies/{companyId}/periods/{id}/open-item-revaluation',
                { params: { path: { companyId, id: periodId } } },
            ),
        )
    ).data;
}
