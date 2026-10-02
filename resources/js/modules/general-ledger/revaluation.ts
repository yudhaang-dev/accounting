import { api, unwrap } from '@/api/client';

/** One foreign currency balance that was off from what the closing rate says it is worth. */
export type RevaluationAdjustment = {
    account_id: string;
    currency: string;
    dimension_set_id: string;
    /** Balance in the transaction currency. */
    transaction_balance: string;
    /** What the ledger carried in the functional currency. */
    carried: string;
    /** What the balance is worth at the closing rate. */
    revalued: string;
    /** The difference posted; positive is a gain. */
    adjustment: string;
};

/**
 * What a run answers: `id` is null when nothing needed adjusting (answered with 200 and no journal).
 * The OpenAPI document leaves `adjustments` untyped (a limit of the generator).
 */
export type RevaluationRun = {
    id: string | null;
    period_id: string;
    journal_id: string | null;
    reversal_journal_id: string | null;
    adjustments: RevaluationAdjustment[];
};

/**
 * Revalues the foreign currency balances of the accounts marked for it, as of the last day of the
 * period (GL-FR-09). Running again only posts what changed since.
 */
export async function runRevaluation(
    companyId: string,
    periodId: string,
): Promise<RevaluationRun> {
    // The generated type of this answer is a bare string (a limit of the generator); it is `{ data: run }`.
    const body = (await unwrap(
        api.POST('/companies/{companyId}/periods/{id}/revaluation', {
            params: { path: { companyId, id: periodId } },
        }),
    )) as unknown as { data: RevaluationRun };

    return body.data;
}
