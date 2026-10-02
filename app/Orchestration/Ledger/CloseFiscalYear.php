<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\ClosingRunData;
use App\Modules\GeneralLedger\Contracts\FiscalYearClose;
use App\Modules\GeneralLedger\Contracts\FiscalYearClosing;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\TransactionEntry\Contracts\UnfinishedEntries;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Closes a fiscal year once the rest of the system is ready (GL-FR-12): no entry dated up to the end
 * of the year is unfinished. The ledger checks its own invariants and posts the closing journal.
 * The reconciliation of subledger control accounts joins the readiness with M6-09. A year that is
 * closed already is answered from its run, whatever has been entered since.
 */
final class CloseFiscalYear
{
    public function __construct(
        private readonly FiscalYearClosing $closing,
        private readonly PeriodDirectory $periods,
        private readonly UnfinishedEntries $entries,
    ) {}

    /**
     * @throws ApiException NOT_FOUND, INVALID_STATE_TRANSITION and what the ledger refuses
     */
    public function __invoke(FiscalYearClose $command): ClosingRunData
    {
        return DB::transaction(function () use ($command): ClosingRunData {
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

            return $this->closing->close($command);
        });
    }
}
