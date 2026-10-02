<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
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

    public function trialBalance(string $companyId, string $from, string $to, ?string $accountId = null, ?array $dimensionSetIds = null): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)
            ->where('l.accounting_date', '<=', $to)
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->when($dimensionSetIds !== null, fn ($query) => $query->whereIn('l.dimension_set_id', $dimensionSetIds))
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
}
