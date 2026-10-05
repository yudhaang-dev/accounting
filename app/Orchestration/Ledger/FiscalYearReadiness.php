<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\FiscalYearClosing;
use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\Subledger\Contracts\ReconciliationStatus;
use App\Modules\TransactionEntry\Contracts\UnfinishedEntries;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;

/**
 * Where a fiscal year stands before and after its closing (GL-FR-12): whether it is closed, and what
 * keeps it from closing. The checks are those the closing itself enforces or depends on, so the
 * screen can show them before anyone asks for the closing.
 */
final class FiscalYearReadiness
{
    public function __construct(
        private readonly FiscalYearClosing $closing,
        private readonly PeriodDirectory $periods,
        private readonly AccountRoles $roles,
        private readonly LedgerPolicies $policies,
        private readonly UnfinishedEntries $entries,
        private readonly ReconciliationStatus $reconciliation,
    ) {}

    /**
     * @throws ApiException NOT_FOUND
     */
    public function __invoke(string $companyId, string $fiscalYearId): FiscalYearReadinessData
    {
        $year = $this->periods->fiscalYear($companyId, $fiscalYearId) ?? throw new ApiException(ErrorCode::NotFound);

        $retained = $this->roles->current($companyId, AccountRole::RetainedEarnings, $year->endDate) !== null;
        $last = $this->periods->forDate($companyId, $year->endDate);
        // A soft-closed last period takes the closing journal only when the ledger policy names its key (GL-FR-04).
        $accepts = $last !== null && ($last->isOpen() || ($last->isSoftClosed() && in_array('system.closing', $this->policies->current($companyId)->softClosedTransactionTypeKeys, true)));
        $unfinished = $this->entries->countThrough($companyId, $year->endDate);
        $differences = count($this->reconciliation->unresolved($companyId, $year->endDate));

        return new FiscalYearReadinessData(
            closing: $this->closing->current($companyId, $year->id),
            ready: $retained && $accepts && $unfinished === 0 && $differences === 0,
            retainedEarningsMapped: $retained,
            lastPeriodAccepts: $accepts,
            lastPeriodName: $last?->name,
            lastPeriodStatus: $last?->status,
            unfinishedEntries: $unfinished,
            reconciliationDifferences: $differences,
        );
    }
}
