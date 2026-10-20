<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\ClosingRunData;
use App\Modules\GeneralLedger\Contracts\FiscalYearClose;
use App\Modules\GeneralLedger\Contracts\FiscalYearClosing;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\Subledger\Contracts\ReconciliationStatus;
use App\Modules\TransactionEntry\Contracts\UnfinishedEntries;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Ledger\ClosingListener;
use Illuminate\Support\Facades\DB;

/**
 * Closes a fiscal year once the rest of the system is ready (GL-FR-12): no entry dated up to the end
 * of the year is unfinished and no control or cash account differs from its subledger at the end of it, unless
 * the difference is waived (SL-FR-09). The ledger checks its own invariants and posts the closing journal. A year that is
 * closed already is answered from its run, whatever has been entered since. Once the closing is committed
 * the {@see ClosingListener}s run, which derive report snapshots (RP-FR-14).
 */
final class CloseFiscalYear
{
    /** @param  iterable<ClosingListener>  $listeners */
    public function __construct(
        private readonly iterable $listeners,
        private readonly FiscalYearClosing $closing,
        private readonly PeriodDirectory $periods,
        private readonly UnfinishedEntries $entries,
        private readonly ReconciliationStatus $reconciliation,
    ) {}

    /**
     * @throws ApiException NOT_FOUND, INVALID_STATE_TRANSITION and what the ledger refuses
     */
    public function __invoke(FiscalYearClose $command): ClosingRunData
    {
        $run = DB::transaction(function () use ($command): ClosingRunData {
            $year = $this->periods->fiscalYear($command->companyId, $command->fiscalYearId)
                ?? throw new ApiException(ErrorCode::NotFound);

            $existing = $this->closing->current($command->companyId, $year->id);

            if ($existing !== null) {
                return new ClosingRunData(...[...get_object_vars($existing), 'replayed' => true]);
            }

            $unfinished = $this->entries->countThrough($command->companyId, $year->endDate);

            if ($unfinished > 0) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                    'entries' => [__('ledger.closing_entries_unfinished', ['count' => $unfinished, 'date' => $year->endDate])],
                ]);
            }

            $differences = $this->reconciliation->unresolved($command->companyId, $year->endDate);

            if ($differences !== []) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                    'reconciliation' => [__('ledger.closing_reconciliation_unresolved', ['count' => count($differences), 'date' => $year->endDate, 'accounts' => implode(', ', array_map(fn ($difference): string => $difference->accountCode, $differences))])],
                ]);
            }

            return $this->closing->close($command);
        });

        if (! $run->replayed) {
            $year = $this->periods->fiscalYear($command->companyId, $command->fiscalYearId) ?? throw new ApiException(ErrorCode::NotFound);

            foreach ($this->listeners as $listener) {
                $listener->fiscalYearClosed($command->companyId, $year->id, $year->startDate, $year->endDate, $command->closedBy);
            }
        }

        return $run;
    }
}
