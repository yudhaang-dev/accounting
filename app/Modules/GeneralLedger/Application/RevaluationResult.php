<?php

namespace App\Modules\GeneralLedger\Application;

/**
 * Outcome of a revaluation run. A run that found nothing to adjust has no run, journal or reversal.
 */
final readonly class RevaluationResult
{
    /**
     * @param  list<array{account_id: string, currency: string, dimension_set_id: string, transaction_balance: string, carried: string, revalued: string, adjustment: string}>  $adjustments
     */
    public function __construct(
        public string $periodId,
        public ?string $runId,
        public ?string $journalId,
        public ?string $reversalJournalId,
        public array $adjustments = [],
    ) {}
}
