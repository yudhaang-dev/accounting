<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\CashTransaction;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a cash transaction cleared, with its date and the user who did it, or undoes that
 * (SL-FR-11). Both changes are audited; nothing else about the transaction changes.
 */
final class ClearCashTransactions
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  string  $clearedOn  `YYYY-MM-DD`, not before the transaction date and not in the future
     *
     * @throws ApiException INVALID_STATE_TRANSITION when it is already cleared
     * @throws ValidationException
     */
    public function clear(string $transactionId, string $clearedOn, int $actorId): CashTransaction
    {
        return DB::transaction(function () use ($transactionId, $clearedOn, $actorId): CashTransaction {
            $transaction = CashTransaction::query()->lockForUpdate()->findOrFail($transactionId);

            if ($transaction->isCleared()) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            if ($clearedOn < $transaction->transaction_date->toDateString()) {
                throw ValidationException::withMessages(['cleared_on' => [__('cash.cleared_before_transaction')]]);
            }

            $transaction->cleared_on = Carbon::parse($clearedOn);
            $transaction->cleared_by = $actorId;
            $transaction->save();

            $this->audit->record(
                'cash_transaction.cleared',
                "Cash transaction {$transaction->id} cleared on {$clearedOn}.",
                $transaction,
                before: ['cleared_on' => null],
                after: ['cleared_on' => $clearedOn],
                companyId: $transaction->company_id,
            );

            return $transaction->refresh();
        });
    }

    /** @throws ApiException INVALID_STATE_TRANSITION when it is not cleared */
    public function unclear(string $transactionId): CashTransaction
    {
        return DB::transaction(function () use ($transactionId): CashTransaction {
            $transaction = CashTransaction::query()->lockForUpdate()->findOrFail($transactionId);

            if (! $transaction->isCleared()) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $previous = $transaction->cleared_on?->toDateString();

            $transaction->cleared_on = null;
            $transaction->cleared_by = null;
            $transaction->save();

            $this->audit->record(
                'cash_transaction.uncleared',
                "Cash transaction {$transaction->id} no longer cleared.",
                $transaction,
                before: ['cleared_on' => $previous],
                after: ['cleared_on' => null],
                companyId: $transaction->company_id,
            );

            return $transaction->refresh();
        });
    }
}
