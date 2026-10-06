<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountLineRow;
use App\Modules\GeneralLedger\Contracts\AccountMovementRow;
use App\Modules\GeneralLedger\Contracts\CurrencyBalanceRow;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerLineRow;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\GeneralLedger\Contracts\TypeMovementRow;
use App\Modules\MasterData\Contracts\Currencies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Sums journal lines per account. The opening balance is everything dated before the range, so the
 * result does not depend on how periods are cut.
 */
final class EloquentLedgerReader implements LedgerReader
{
    public function __construct(private readonly Currencies $currencies) {}

    public function trialBalance(string $companyId, string $from, string $to, ?string $accountId = null, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null, array $excludedTransactionTypeKeys = []): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->when($transactionTypeKeys !== null || $source !== null || $excludedTransactionTypeKeys !== [], fn ($query) => $query->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id')))
            ->where('l.company_id', $companyId)
            ->where('l.accounting_date', '<=', $to)
            ->when($excludedTransactionTypeKeys !== [], fn ($query) => $query->where(fn ($keys) => $keys->whereNull('j.transaction_type_key')->orWhereNotIn('j.transaction_type_key', $excludedTransactionTypeKeys)))
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->when($dimensionSetIds !== null, fn ($query) => $query->whereIn('l.dimension_set_id', $dimensionSetIds))
            ->when($transactionTypeKeys !== null, fn ($query) => $query->whereIn('j.transaction_type_key', $transactionTypeKeys))
            ->when($source !== null, fn ($query) => $query->where('j.source', $source->value))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.class')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.class')
            ->selectRaw('coalesce(sum(case when l.accounting_date < ? then l.debit - l.credit else 0 end), 0) as opening', [$from])
            ->selectRaw('coalesce(sum(case when l.accounting_date >= ? then l.debit else 0 end), 0) as debit', [$from])
            ->selectRaw('coalesce(sum(case when l.accounting_date >= ? then l.credit else 0 end), 0) as credit', [$from])
            ->get();

        $scale = $this->currencies->functionalScale($companyId);
        $result = [];

        foreach ($rows as $row) {
            $opening = BigDecimal::of($row->opening);
            $debit = BigDecimal::of($row->debit);
            $credit = BigDecimal::of($row->credit);

            if ($opening->isZero() && $debit->isZero() && $credit->isZero()) {
                continue;
            }

            $closing = $opening->plus($debit)->minus($credit);

            $result[] = new TrialBalanceRow(
                $row->id,
                $row->code,
                $row->name,
                AccountClass::from($row->class),
                $this->side($opening, true, $scale),
                $this->side($opening, false, $scale),
                $this->amount($debit, $scale),
                $this->amount($credit, $scale),
                $this->side($closing, true, $scale),
                $this->side($closing, false, $scale),
            );
        }

        return $result;
    }

    public function movementByTransactionType(string $companyId, string $from, string $to, ?string $accountId = null, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id'))
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)
            ->whereBetween('l.accounting_date', [$from, $to])
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->when($dimensionSetIds !== null, fn ($query) => $query->whereIn('l.dimension_set_id', $dimensionSetIds))
            ->when($transactionTypeKeys !== null, fn ($query) => $query->whereIn('j.transaction_type_key', $transactionTypeKeys))
            ->when($source !== null, fn ($query) => $query->where('j.source', $source->value))
            ->groupBy('l.account_id', 'a.code', 'j.transaction_type_key')
            ->havingRaw('sum(l.debit) <> 0 or sum(l.credit) <> 0')
            ->orderBy('a.code')
            ->orderBy('j.transaction_type_key')
            ->selectRaw('l.account_id, j.transaction_type_key, sum(l.debit) as debit, sum(l.credit) as credit')
            ->get();

        $scale = $this->currencies->functionalScale($companyId);

        return array_values($rows->map(fn ($row): TypeMovementRow => new TypeMovementRow($row->account_id, $row->transaction_type_key, $this->amount(BigDecimal::of($row->debit), $scale), $this->amount(BigDecimal::of($row->credit), $scale)))->all());
    }

    public function counterMovement(string $companyId, string $from, string $to, array $accountIds, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id'))
            ->where('l.company_id', $companyId)
            ->whereBetween('l.accounting_date', [$from, $to])
            ->whereNotIn('l.account_id', $accountIds)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('gl_journal_lines as c')
                ->whereColumn('c.company_id', 'l.company_id')->whereColumn('c.journal_id', 'l.journal_id')->whereIn('c.account_id', $accountIds))
            ->where(fn ($keys) => $keys->whereNull('j.transaction_type_key')->orWhereNotIn('j.transaction_type_key', [SystemTransactionKeys::OPENING_BALANCE, SystemTransactionKeys::CLOSING]))
            ->when($dimensionSetIds !== null, fn ($query) => $query->whereIn('l.dimension_set_id', $dimensionSetIds))
            ->when($transactionTypeKeys !== null, fn ($query) => $query->whereIn('j.transaction_type_key', $transactionTypeKeys))
            ->when($source !== null, fn ($query) => $query->where('j.source', $source->value))
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->groupBy('l.account_id', 'a.code', 'j.transaction_type_key')
            ->havingRaw('sum(l.debit) <> 0 or sum(l.credit) <> 0')
            ->orderBy('a.code')
            ->orderBy('j.transaction_type_key')
            ->selectRaw('l.account_id, j.transaction_type_key, sum(l.debit) as debit, sum(l.credit) as credit')
            ->get();

        $scale = $this->currencies->functionalScale($companyId);

        return array_values($rows->map(fn ($row): TypeMovementRow => new TypeMovementRow($row->account_id, $row->transaction_type_key, $this->amount(BigDecimal::of($row->debit), $scale), $this->amount(BigDecimal::of($row->credit), $scale)))->all());
    }

    public function currencyBalances(string $companyId, string $asOf, ?string $accountId = null, ?array $dimensionSetIds = null): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)
            ->where('l.accounting_date', '<=', $asOf)
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->when($dimensionSetIds !== null, fn ($query) => $query->whereIn('l.dimension_set_id', $dimensionSetIds))
            ->groupBy('a.id', 'a.code', 'a.name', 'l.currency')
            ->orderBy('a.code')
            ->orderBy('l.currency')
            ->selectRaw('a.id, a.code, a.name, l.currency')
            ->selectRaw('sum(case when l.debit > 0 then l.transaction_amount else 0 end) as transaction_debit')
            ->selectRaw('sum(case when l.credit > 0 then l.transaction_amount else 0 end) as transaction_credit')
            ->selectRaw('sum(l.debit) as debit, sum(l.credit) as credit')
            ->get();

        $functionalScale = $this->currencies->functionalScale($companyId);
        $scales = [];
        $result = [];

        foreach ($rows as $row) {
            $scale = $scales[$row->currency] ??= $this->currencies->find($companyId, $row->currency)->scale ?? $functionalScale;

            $result[] = new CurrencyBalanceRow(
                $row->id,
                $row->code,
                $row->name,
                $row->currency,
                $this->amount(BigDecimal::of($row->transaction_debit), $scale),
                $this->amount(BigDecimal::of($row->transaction_credit), $scale),
                $this->amount(BigDecimal::of($row->debit), $functionalScale),
                $this->amount(BigDecimal::of($row->credit), $functionalScale),
            );
        }

        return $result;
    }

    /**
     * @param  int<0, max>  $scale
     */
    private function side(BigDecimal $net, bool $debitSide, int $scale): string
    {
        $onThisSide = $debitSide ? $net->isPositive() : $net->isNegative();

        return $this->amount($onThisSide ? $net->abs() : BigDecimal::zero(), $scale);
    }

    /**
     * @param  int<0, max>  $scale
     */
    private function amount(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }

    public function movementByDimensionSet(string $companyId, string $from, string $to, array $classes): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id'))
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)
            ->whereIn('a.class', array_map(fn (AccountClass $class): string => $class->value, $classes))
            ->whereBetween('l.accounting_date', [$from, $to])
            ->whereRaw('j.transaction_type_key is distinct from ?', [EloquentFiscalYearClosing::TRANSACTION_TYPE_KEY])
            ->groupBy('l.account_id', 'a.code', 'l.dimension_set_id')
            ->havingRaw('sum(l.debit - l.credit) <> 0')
            ->orderBy('a.code')
            ->orderBy('l.dimension_set_id')
            ->selectRaw('l.account_id, l.dimension_set_id, sum(l.debit) as debit, sum(l.credit) as credit')
            ->get();

        $scale = $this->currencies->functionalScale($companyId);
        $result = [];

        foreach ($rows as $row) {
            $result[] = new AccountMovementRow($row->account_id, $row->dimension_set_id, $this->amount(BigDecimal::of($row->debit), $scale), $this->amount(BigDecimal::of($row->credit), $scale));
        }

        return $result;
    }

    public function linesNotFromSource(string $companyId, string $accountId, string $asOf, JournalSource $source): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id'))
            ->where('l.company_id', $companyId)
            ->where('l.account_id', $accountId)
            ->where('l.accounting_date', '<=', $asOf)
            ->where('j.source', '<>', $source->value)
            ->orderBy('l.accounting_date')->orderBy('j.number')->orderBy('l.line_number')
            ->get(['j.id as journal_id', 'j.number', 'l.accounting_date', 'j.source', 'l.debit', 'l.credit', 'l.description']);

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new AccountLineRow($row->journal_id, $row->number, substr($row->accounting_date, 0, 10), JournalSource::from($row->source), (string) $row->debit, (string) $row->credit, $row->description);
        }

        return $lines;
    }

    public function accountLines(string $companyId, string $from, string $to, string $accountId, ?array $dimensionSetIds = null, ?array $transactionTypeKeys = null, ?JournalSource $source = null): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id'))
            ->where('l.company_id', $companyId)
            ->where('l.account_id', $accountId)
            ->whereBetween('l.accounting_date', [$from, $to])
            ->when($dimensionSetIds !== null, fn ($query) => $query->whereIn('l.dimension_set_id', $dimensionSetIds))
            ->when($transactionTypeKeys !== null, fn ($query) => $query->whereIn('j.transaction_type_key', $transactionTypeKeys))
            ->when($source !== null, fn ($query) => $query->where('j.source', $source->value))
            ->orderBy('l.accounting_date')->orderBy('j.number')->orderBy('l.line_number')
            ->get(['j.id as journal_id', 'j.number', 'j.entry_id', 'l.account_id', 'l.accounting_date', 'j.source', 'j.transaction_type_key', 'j.source_references', 'l.debit', 'l.credit', 'l.currency', 'l.transaction_amount', 'l.exchange_rate', 'l.description']);

        $scale = $this->currencies->functionalScale($companyId);

        return array_values($rows->map(fn ($row): LedgerLineRow => new LedgerLineRow(
            $row->journal_id,
            $row->number,
            $row->entry_id,
            $row->account_id,
            substr($row->accounting_date, 0, 10),
            JournalSource::from($row->source),
            $row->transaction_type_key,
            $row->source_references === null ? null : json_decode($row->source_references, true),
            $this->amount(BigDecimal::of($row->debit), $scale),
            $this->amount(BigDecimal::of($row->credit), $scale),
            $row->currency,
            (string) $row->transaction_amount,
            (string) $row->exchange_rate,
            $row->description,
        ))->all());
    }
}
