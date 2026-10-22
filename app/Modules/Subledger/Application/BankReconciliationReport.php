<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\Subledger\Domain\BankStatement;
use App\Modules\Subledger\Domain\CashAccount;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reconciles the book balance of a cash account with the balance on a bank statement on its closing date
 * (SL-FR-13). The statement balance plus the receipts the bank has not shown, less the payments it has not
 * shown, equals the book balance plus the bank lines the books do not hold, less those that are payments.
 * What is not matched on the date is listed. A transaction or line whose match is dated after the closing
 * date still counts as outstanding on it.
 *
 * @phpstan-type Outstanding array{cash_transaction_id: string, transaction_date: string, direction: string, amount: string, reference: string|null}
 * @phpstan-type Unrecorded array{line_id: string, statement_id: string, line_number: int, transaction_date: string, direction: string, amount: string, description: string|null, reference: string|null}
 * @phpstan-type Report array{statement_id: string, cash_account_id: string, currency: string, closing_date: string, statement_balance: string, book_balance: string, outstanding_in: string, outstanding_out: string, unrecorded_in: string, unrecorded_out: string, adjusted_statement_balance: string, adjusted_book_balance: string, difference: string, reconciled: bool, outstanding_transactions: list<Outstanding>, unrecorded_lines: list<Unrecorded>}
 */
final class BankReconciliationReport
{
    public function __construct(private readonly LedgerReader $ledger) {}

    /** @return Report */
    public function __invoke(BankStatement $statement): array
    {
        $account = CashAccount::query()->findOrFail($statement->cash_account_id);
        $date = $statement->closing_date->toDateString();

        $book = BigDecimal::zero();

        foreach ($this->ledger->currencyBalances($account->company_id, $date, $account->account_id) as $balance) {
            if ($balance->currency === $account->currency) {
                $book = $book->plus($balance->debit)->minus($balance->credit);
            }
        }

        $outstanding = DB::table('sl_cash_transactions as t')
            ->leftJoin('sl_bank_statement_lines as l', fn ($join) => $join->on('l.company_id', '=', 't.company_id')->on('l.cash_transaction_id', '=', 't.id'))
            ->where('t.company_id', $account->company_id)->where('t.cash_account_id', $account->id)->where('t.transaction_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('l.id')->orWhere('l.transaction_date', '>', $date))
            ->orderBy('t.transaction_date')->orderBy('t.id')
            ->get(['t.id', 't.transaction_date', 't.direction', 't.amount', 't.reference']);

        $unrecorded = DB::table('sl_bank_statement_lines as l')
            ->leftJoin('sl_cash_transactions as t', fn ($join) => $join->on('t.company_id', '=', 'l.company_id')->on('t.id', '=', 'l.cash_transaction_id'))
            ->where('l.company_id', $account->company_id)->where('l.cash_account_id', $account->id)->where('l.transaction_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('t.id')->orWhere('t.transaction_date', '>', $date))
            ->orderBy('l.transaction_date')->orderBy('l.statement_id')->orderBy('l.line_number')
            ->get(['l.id', 'l.statement_id', 'l.line_number', 'l.transaction_date', 'l.direction', 'l.amount', 'l.description', 'l.reference']);

        $outstandingIn = $this->total($outstanding, 'in');
        $outstandingOut = $this->total($outstanding, 'out');
        $unrecordedIn = $this->total($unrecorded, 'in');
        $unrecordedOut = $this->total($unrecorded, 'out');

        $adjustedStatement = $statement->closing_balance->plus($outstandingIn)->minus($outstandingOut);
        $adjustedBook = $book->plus($unrecordedIn)->minus($unrecordedOut);
        $difference = $adjustedStatement->minus($adjustedBook);

        return [
            'statement_id' => $statement->id,
            'cash_account_id' => $account->id,
            'currency' => $account->currency,
            'closing_date' => $date,
            'statement_balance' => (string) $statement->closing_balance,
            'book_balance' => (string) $book->toScale(6),
            'outstanding_in' => (string) $outstandingIn->toScale(6),
            'outstanding_out' => (string) $outstandingOut->toScale(6),
            'unrecorded_in' => (string) $unrecordedIn->toScale(6),
            'unrecorded_out' => (string) $unrecordedOut->toScale(6),
            'adjusted_statement_balance' => (string) $adjustedStatement->toScale(6),
            'adjusted_book_balance' => (string) $adjustedBook->toScale(6),
            'difference' => (string) $difference->toScale(6),
            'reconciled' => $difference->isZero(),
            'outstanding_transactions' => $this->outstandingList($outstanding),
            'unrecorded_lines' => $this->unrecordedList($unrecorded),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<Outstanding>
     */
    private function outstandingList(Collection $rows): array
    {
        $list = [];

        foreach ($rows as $row) {
            $list[] = [
                'cash_transaction_id' => (string) $row->id,
                'transaction_date' => substr((string) $row->transaction_date, 0, 10),
                'direction' => (string) $row->direction,
                'amount' => (string) BigDecimal::of($row->amount)->toScale(6),
                'reference' => $row->reference === null ? null : (string) $row->reference,
            ];
        }

        return $list;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<Unrecorded>
     */
    private function unrecordedList(Collection $rows): array
    {
        $list = [];

        foreach ($rows as $row) {
            $list[] = [
                'line_id' => (string) $row->id,
                'statement_id' => (string) $row->statement_id,
                'line_number' => (int) $row->line_number,
                'transaction_date' => substr((string) $row->transaction_date, 0, 10),
                'direction' => (string) $row->direction,
                'amount' => (string) BigDecimal::of($row->amount)->toScale(6),
                'description' => $row->description === null ? null : (string) $row->description,
                'reference' => $row->reference === null ? null : (string) $row->reference,
            ];
        }

        return $list;
    }

    /** @param  Collection<int, stdClass>  $rows */
    private function total(Collection $rows, string $direction): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($rows as $row) {
            if ($row->direction === $direction) {
                $total = $total->plus((string) $row->amount);
            }
        }

        return $total;
    }
}
