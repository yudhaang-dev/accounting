<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\BankStatement;
use App\Modules\Subledger\Domain\BankStatementLine;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matches the lines of a bank statement to the cash transactions of the same account (SL-FR-13). A match is
 * one to one, needs the same direction and amount, and puts the cleared mark on the transaction on the date
 * of the bank (or the date of the transaction when the bank is earlier), unless a user cleared it before.
 * Unmatching takes back only the mark that the match put.
 *
 * Suggestions rank the open transactions of the same direction and amount by the closeness of the date and
 * by whether the reference agrees.
 *
 * @phpstan-type Suggestion array{line_id: string, candidates: list<array{cash_transaction_id: string, score: int, date_difference_days: int, reference_match: bool}>}
 */
final class MatchBankStatementLines
{
    public const DEFAULT_WINDOW_DAYS = 7;

    public const MAX_CANDIDATES = 5;

    public function __construct(
        private readonly ClearCashTransactions $clearing,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws ApiException INVALID_STATE_TRANSITION when the line or the transaction is already matched
     * @throws ValidationException
     */
    public function match(string $lineId, string $transactionId, int $actorId): BankStatementLine
    {
        return DB::transaction(function () use ($lineId, $transactionId, $actorId): BankStatementLine {
            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($lineId);
            $transaction = CashTransaction::query()->lockForUpdate()->find($transactionId);

            if ($line->isMatched()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['line' => [__('bank.line_already_matched')]]);
            }

            if ($transaction === null || $transaction->cash_account_id !== $line->cash_account_id) {
                throw ValidationException::withMessages(['cash_transaction_id' => [__('bank.transaction_other_account')]]);
            }

            if ($transaction->direction !== $line->direction || ! $transaction->amount->isEqualTo($line->amount)) {
                throw ValidationException::withMessages(['cash_transaction_id' => [__('bank.transaction_differs', ['direction' => $line->direction->value, 'amount' => (string) $line->amount])]]);
            }

            if (BankStatementLine::query()->where('cash_transaction_id', $transaction->id)->exists()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['cash_transaction_id' => [__('bank.transaction_already_matched')]]);
            }

            $clearedByMatch = false;

            if (! $transaction->isCleared()) {
                $date = max($line->transaction_date->toDateString(), $transaction->transaction_date->toDateString());
                $this->clearing->clear($transaction->id, $date, $actorId);
                $clearedByMatch = true;
            }

            $line->forceFill([
                'cash_transaction_id' => $transaction->id,
                'matched_at' => now(),
                'matched_by' => $actorId,
                'cleared_by_match' => $clearedByMatch,
            ])->save();

            $this->audit->record(
                'bank_statement_line.matched',
                "Statement line {$line->line_number} matched to cash transaction {$transaction->id}.",
                $line,
                before: ['cash_transaction_id' => null],
                after: ['cash_transaction_id' => $transaction->id],
                companyId: $line->company_id,
            );

            return $line->refresh();
        });
    }

    /** @throws ApiException INVALID_STATE_TRANSITION when the line is not matched */
    public function unmatch(string $lineId): BankStatementLine
    {
        return DB::transaction(function () use ($lineId): BankStatementLine {
            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($lineId);

            if (! $line->isMatched()) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['line' => [__('bank.line_not_matched')]]);
            }

            $transactionId = (string) $line->cash_transaction_id;

            if ($line->cleared_by_match) {
                $transaction = CashTransaction::query()->lockForUpdate()->findOrFail($transactionId);

                if ($transaction->isCleared()) {
                    $this->clearing->unclear($transactionId);
                }
            }

            $line->forceFill(['cash_transaction_id' => null, 'matched_at' => null, 'matched_by' => null, 'cleared_by_match' => false])->save();

            $this->audit->record(
                'bank_statement_line.unmatched',
                "Statement line {$line->line_number} no longer matched to cash transaction {$transactionId}.",
                $line,
                before: ['cash_transaction_id' => $transactionId],
                after: ['cash_transaction_id' => null],
                companyId: $line->company_id,
            );

            return $line->refresh();
        });
    }

    /**
     * Candidates for every unmatched line of a statement, best first; lines without a candidate are left out.
     *
     * @return list<Suggestion>
     */
    public function suggestions(BankStatement $statement, int $windowDays = self::DEFAULT_WINDOW_DAYS): array
    {
        $lines = BankStatementLine::query()->where('statement_id', $statement->id)->whereNull('cash_transaction_id')->orderBy('line_number')->get();
        $result = [];

        if ($lines->isEmpty()) {
            return $result;
        }

        $open = CashTransaction::query()
            ->where('cash_account_id', $statement->cash_account_id)
            ->whereNotIn('id', BankStatementLine::query()->whereNotNull('cash_transaction_id')->where('cash_account_id', $statement->cash_account_id)->select('cash_transaction_id'))
            ->get();

        foreach ($lines as $line) {
            $candidates = [];

            foreach ($open as $transaction) {
                if ($transaction->direction !== $line->direction || ! $transaction->amount->isEqualTo($line->amount)) {
                    continue;
                }

                $difference = (int) abs($transaction->transaction_date->diffInDays($line->transaction_date, absolute: true));

                if ($difference > $windowDays) {
                    continue;
                }

                $referenceMatch = $this->referencesAgree($line->reference, $transaction->reference) || $this->referencesAgree($line->description, $transaction->reference);

                $candidates[] = [
                    'cash_transaction_id' => $transaction->id,
                    'score' => ($referenceMatch ? 100 : 0) + ($windowDays - $difference),
                    'date_difference_days' => $difference,
                    'reference_match' => $referenceMatch,
                ];
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['cash_transaction_id'], $b['cash_transaction_id']));
            $result[] = ['line_id' => $line->id, 'candidates' => array_slice($candidates, 0, self::MAX_CANDIDATES)];
        }

        return $result;
    }

    /** The reference of the book is inside the text of the bank line, ignoring case and spacing. */
    private function referencesAgree(?string $bankText, ?string $reference): bool
    {
        if ($bankText === null || $reference === null || trim($reference) === '') {
            return false;
        }

        $clean = fn (string $text): string => strtolower(preg_replace('/\s+/', '', $text) ?? $text);

        return str_contains($clean($bankText), $clean($reference));
    }
}
