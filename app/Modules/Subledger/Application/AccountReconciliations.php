<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\Subledger\Contracts\ReconciliationDifference;
use App\Modules\Subledger\Contracts\ReconciliationStatus;
use App\Modules\Subledger\Domain\ReconciliationRun;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Compares the ledger balance of every control and cash account with the total of what the subledger holds
 * for it on a date (SL-FR-09): the open items of a control account, at the functional value they carried that
 * day, and the cash transactions of the accounts of a cash account, in its currency. What the ledger holds
 * that the subledger did not post, such as an override journal (GL-FR-07), is listed as the cause of a
 * difference. A difference is waived with a reason by whoever may, and is audited.
 *
 * @phpstan-type Cause array{journal_id: string, number: string, accounting_date: string, source: string, debit: string, credit: string, description: string|null}
 * @phpstan-type Row array{kind: string, account_id: string, account_code: string, currency: string, ledger_balance: BigDecimal, detail_total: BigDecimal, difference: BigDecimal, causes: list<Cause>}
 */
final class AccountReconciliations implements ReconciliationStatus
{
    public function __construct(
        private readonly LedgerReader $ledger,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    /**
     * Reconciles every account and keeps what it found.
     *
     * @return list<ReconciliationRun>
     */
    public function run(string $companyId, string $asOf, ?int $userId): array
    {
        return DB::transaction(function () use ($companyId, $asOf, $userId): array {
            $runs = [];

            foreach ($this->compute($companyId, $asOf) as $row) {
                $runs[] = ReconciliationRun::create([
                    ...$row,
                    'as_of' => $asOf,
                    'status' => $row['difference']->isZero() ? 'balanced' : 'difference',
                    'run_by' => $userId,
                ])->refresh();
            }

            $this->audit->record(
                'reconciliation.run',
                "Reconciliation of the control and cash accounts on {$asOf}.",
                properties: ['as_of' => $asOf, 'accounts' => count($runs), 'differences' => count(array_filter($runs, fn (ReconciliationRun $run): bool => $run->status === 'difference'))],
                companyId: $companyId,
            );

            return $runs;
        });
    }

    /**
     * Takes a difference out of the way of closing the year, for the reason given.
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the run has no difference or has been waived
     */
    public function waive(string $id, string $reason, int $userId): ReconciliationRun
    {
        return DB::transaction(function () use ($id, $reason, $userId): ReconciliationRun {
            $run = ReconciliationRun::query()->lockForUpdate()->findOrFail($id);

            if ($run->status !== 'difference') {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['status' => [__('documents.reconciliation_not_waivable', ['status' => $run->status])]]);
            }

            $run->forceFill(['status' => 'waived', 'waiver_reason' => $reason, 'waived_by' => $userId, 'waived_at' => now()])->save();

            $this->audit->record(
                'reconciliation.waived',
                "Difference of {$run->difference} on account {$run->account_code} on {$run->as_of->toDateString()} waived.",
                $run,
                before: ['status' => 'difference'],
                after: ['status' => 'waived'],
                properties: ['reason' => $reason, 'difference' => (string) $run->difference],
                companyId: $run->company_id,
            );

            return $run->refresh();
        });
    }

    public function unresolved(string $companyId, string $asOf): array
    {
        $waived = [];

        foreach (ReconciliationRun::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('as_of', $asOf)->where('status', 'waived')->get() as $run) {
            $waived[$run->account_id.'|'.$run->currency.'|'.$run->difference] = true;
        }

        $result = [];

        foreach ($this->compute($companyId, $asOf) as $row) {
            if ($row['difference']->isZero() || isset($waived[$row['account_id'].'|'.$row['currency'].'|'.$row['difference']])) {
                continue;
            }

            $result[] = new ReconciliationDifference($row['kind'], $row['account_id'], $row['account_code'], $row['currency'], (string) $row['difference']);
        }

        return $result;
    }

    /**
     * @return list<Row>
     */
    private function compute(string $companyId, string $asOf): array
    {
        $functional = $this->companies->find($companyId)->functionalCurrency ?? '';
        $balances = $this->ledger->currencyBalances($companyId, $asOf);
        $rows = [];

        foreach ($this->controlDetails($companyId, $asOf) as $accountId => $detail) {
            $ledger = BigDecimal::zero();

            foreach ($balances as $balance) {
                if ($balance->accountId === $accountId) {
                    $ledger = $ledger->plus($balance->functionalDebit)->minus($balance->functionalCredit);
                }
            }

            $rows[] = $this->row('control', $companyId, $accountId, $functional, $ledger, $detail, $asOf);
        }

        foreach ($this->cashDetails($companyId, $asOf) as $key => $detail) {
            [$accountId, $currency] = explode('|', $key);
            $ledger = BigDecimal::zero();

            foreach ($balances as $balance) {
                if ($balance->accountId === $accountId && $balance->currency === $currency) {
                    $ledger = $ledger->plus($balance->debit)->minus($balance->credit);
                }
            }

            $rows[] = $this->row('cash', $companyId, $accountId, $currency, $ledger, $detail, $asOf);
        }

        return $rows;
    }

    /**
     * @return Row
     */
    private function row(string $kind, string $companyId, string $accountId, string $currency, BigDecimal $ledger, BigDecimal $detail, string $asOf): array
    {
        $difference = $ledger->minus($detail);
        $causes = [];

        if (! $difference->isZero()) {
            foreach ($this->ledger->linesNotFromSource($companyId, $accountId, $asOf, JournalSource::Subledger) as $line) {
                $causes[] = [
                    'journal_id' => $line->journalId,
                    'number' => $line->journalNumber,
                    'accounting_date' => $line->accountingDate,
                    'source' => $line->source->value,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'description' => $line->description,
                ];
            }
        }

        return [
            'kind' => $kind,
            'account_id' => $accountId,
            'account_code' => $this->accounts->find($companyId, $accountId)->code ?? $accountId,
            'currency' => $currency,
            'ledger_balance' => $ledger->toScale(6),
            'detail_total' => $detail->toScale(6),
            'difference' => $difference->toScale(6),
            'causes' => $causes,
        ];
    }

    /**
     * What the open items add up to on the date for each control account, debit side positive: a document
     * counts from its accounting date until it is reversed, an allocation carries the exchange difference it
     * settled from its date until it is cancelled, and a revaluation the adjustment it posted until its
     * reversal, which makes the sum what the ledger holds on the control account.
     *
     * @return array<string, BigDecimal> by account
     */
    private function controlDetails(string $companyId, string $asOf): array
    {
        /** @var list<string> $accountIds */
        $accountIds = DB::table('sl_posting_profile_accounts')->where('company_id', $companyId)->distinct()->pluck('account_id')->all();

        if ($accountIds === []) {
            return [];
        }

        $details = array_fill_keys($accountIds, BigDecimal::zero());
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        // The control account a document posted to is the one of its journal that holds the amount of the document.
        $account = "(select l.account_id from gl_journal_lines l where l.company_id = d.company_id and l.journal_id = d.journal_id and l.account_id in ({$placeholders}) and l.transaction_amount > 0 order by l.line_number limit 1)";

        $items = DB::select(
            "select {$account} as account_id, o.side, o.original_functional as amount
             from sl_open_items o join sl_documents d on d.company_id = o.company_id and d.id = o.document_id
             left join sl_document_reversals r on r.company_id = d.company_id and r.document_id = d.id
             where o.company_id = ? and d.accounting_date <= ? and (r.id is null or r.reversal_date > ?)",
            [...$accountIds, $companyId, $asOf, $asOf],
        );
        $allocations = DB::select(
            "select {$account} as account_id, 'debit' as side, a.realized_difference as amount
             from sl_allocations a join sl_open_items o on o.company_id = a.company_id and o.id = a.debit_item_id
             join sl_documents d on d.company_id = o.company_id and d.id = o.document_id
             where a.company_id = ? and a.allocation_date <= ? and (a.status = 'active' or a.cancelled_on > ?)",
            [...$accountIds, $companyId, $asOf, $asOf],
        );
        $revaluations = DB::select(
            "select {$account} as account_id, o.side, i.adjustment as amount
             from sl_revaluation_items i join sl_revaluation_runs r on r.company_id = i.company_id and r.id = i.run_id
             join gl_journals j on j.id = r.journal_id left join gl_journals rj on rj.id = r.reversal_journal_id
             join sl_open_items o on o.company_id = i.company_id and o.id = i.open_item_id
             join sl_documents d on d.company_id = o.company_id and d.id = o.document_id
             where i.company_id = ? and j.accounting_date <= ? and (rj.id is null or rj.accounting_date > ?)",
            [...$accountIds, $companyId, $asOf, $asOf],
        );

        foreach ([...$items, ...$allocations, ...$revaluations] as $row) {
            if ($row->account_id === null) {
                continue;
            }

            $amount = BigDecimal::of($row->amount);
            $details[$row->account_id] = $details[$row->account_id]->plus($row->side === 'debit' ? $amount : $amount->negated());
        }

        return $details;
    }

    /**
     * The cash on the date for each ledger account and currency of the cash accounts, receipts positive.
     *
     * @return array<string, BigDecimal> by `account|currency`
     */
    private function cashDetails(string $companyId, string $asOf): array
    {
        $details = [];

        foreach (DB::table('sl_cash_accounts')->where('company_id', $companyId)->get(['account_id', 'currency']) as $account) {
            $details["{$account->account_id}|{$account->currency}"] = BigDecimal::zero();
        }

        $moved = DB::table('sl_cash_transactions as t')
            ->join('sl_cash_accounts as c', fn ($join) => $join->on('c.company_id', '=', 't.company_id')->on('c.id', '=', 't.cash_account_id'))
            ->where('t.company_id', $companyId)->where('t.transaction_date', '<=', $asOf)
            ->groupBy('c.account_id', 't.currency')
            ->selectRaw("c.account_id, t.currency, sum(case when t.direction = 'in' then t.amount else -t.amount end) as total")
            ->get();

        foreach ($moved as $row) {
            $details["{$row->account_id}|{$row->currency}"] = BigDecimal::of($row->total);
        }

        return $details;
    }
}
