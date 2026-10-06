<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\LedgerLineRow;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\GeneralLedger\Contracts\TypeMovementRow;

/** The part of the ledger that a filter and a data scope leave, readable for any date range. */
final class LedgerSlice
{
    /** @param  list<string>|null  $dimensionSetIds */
    public function __construct(
        private readonly LedgerReader $ledger,
        private readonly string $companyId,
        private readonly ReportFilter $filter,
        private readonly ?array $dimensionSetIds,
    ) {}

    /** @return list<TypeMovementRow> */
    public function movementByTransactionType(string $from, string $to): array
    {
        return $this->ledger->movementByTransactionType(
            $this->companyId,
            $from,
            $to,
            $this->filter->accountId,
            $this->dimensionSetIds,
            $this->filter->transactionTypeKeys === [] ? null : $this->filter->transactionTypeKeys,
            $this->filter->source,
        );
    }

    /**
     * @param  list<string>  $cashAccountIds
     * @return list<TypeMovementRow>
     */
    public function cashCounterMovement(string $from, string $to, array $cashAccountIds): array
    {
        return $this->ledger->counterMovement(
            $this->companyId,
            $from,
            $to,
            $cashAccountIds,
            $this->dimensionSetIds,
            $this->filter->transactionTypeKeys === [] ? null : $this->filter->transactionTypeKeys,
            $this->filter->source,
        );
    }

    /**
     * @param  list<string>  $excludedTransactionTypeKeys
     * @return list<TrialBalanceRow>
     */
    public function trialBalance(string $from, string $to, array $excludedTransactionTypeKeys = []): array
    {
        return $this->ledger->trialBalance(
            $this->companyId,
            $from,
            $to,
            $this->filter->accountId,
            $this->dimensionSetIds,
            $this->filter->transactionTypeKeys === [] ? null : $this->filter->transactionTypeKeys,
            $this->filter->source,
            $excludedTransactionTypeKeys,
        );
    }

    /** @return list<LedgerLineRow> */
    public function accountLines(string $from, string $to, string $accountId): array
    {
        return $this->ledger->accountLines(
            $this->companyId,
            $from,
            $to,
            $accountId,
            $this->dimensionSetIds,
            $this->filter->transactionTypeKeys === [] ? null : $this->filter->transactionTypeKeys,
            $this->filter->source,
        );
    }
}
