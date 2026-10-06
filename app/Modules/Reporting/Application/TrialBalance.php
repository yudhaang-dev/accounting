<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\OpeningBalances;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Trial balance of a date range (RP-FR-05): opening balance, debit and credit movement and closing
 * balance per account, with totals and a visible `balanced` flag (RP-AC-01).
 */
final class TrialBalance
{
    private const COLUMNS = ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'];

    public function __construct(
        private readonly ReportLedger $ledger,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
        private readonly OpeningBalances $openingBalances,
        private readonly HistoryCoverage $coverage,
    ) {}

    /**
     * The filter keeps only the lines in a dimension value or any value below it, of journals with
     * one of the transaction type keys and from the source (RP-FR-04). A restricted `$scope` keeps
     * only the lines inside it, whatever the filter, and the result says so with `restricted`
     * (IA-FR-03): its totals cover those lines only.
     *
     * With `$adjustmentKeys` the result also has a `workpaper`: per account the closing balance before
     * the adjustments (the journals with those keys left out), the adjustments (what those journals
     * add to the closing balance, as a net debit or credit) and the closing balance after them, which is
     * the closing balance of the report (RP-FR-05, RP-AC-11). `conversion_batch` says which opening
     * balance batch the ledger started from, if any.
     *
     * @param  list<string>  $adjustmentKeys
     * @return array{parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, balanced: bool, totals: array<string, string>, rows: list<array<string, string>>, conversion_batch: array<string, string|null>|null, workpaper?: array<string, mixed>}
     */
    public function __invoke(string $companyId, string $from, string $to, ?ReportFilter $filter = null, ?DataScope $scope = null, array $adjustmentKeys = []): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter;

        $slice = $this->ledger->slice($companyId, $filter, $scope);
        $rows = array_map($this->row(...), $slice->trialBalance($from, $to));

        $scale = $this->currencies->functionalScale($companyId);
        $totals = array_fill_keys(self::COLUMNS, BigDecimal::zero());

        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $totals[$column] = $totals[$column]->plus($row[$column]);
            }
        }

        $balanced = $totals['opening_debit']->isEqualTo($totals['opening_credit'])
            && $totals['debit']->isEqualTo($totals['credit'])
            && $totals['closing_debit']->isEqualTo($totals['closing_credit']);

        return [
            'parameters' => ['from' => $from, 'to' => $to, ...$filter->toArray()],
            'functional_currency' => $company->functionalCurrency,
            'restricted' => $scope->isRestricted(),
            'balanced' => $balanced,
            ...$this->coverage->assess($companyId, $from, $to),
            'totals' => array_map(fn (BigDecimal $total): string => $this->format($total, $scale), $totals),
            'rows' => array_map(fn (array $row): array => array_map(
                fn (string|BigDecimal $value): string => $value instanceof BigDecimal ? $this->format($value, $scale) : $value,
                $row,
            ), $rows),
            'conversion_batch' => $this->conversionBatch($companyId),
            ...($adjustmentKeys === [] ? [] : ['workpaper' => $this->workpaper($slice->trialBalance($from, $to), $slice->trialBalance($from, $to, $adjustmentKeys), $adjustmentKeys, $scale)]),
        ];
    }

    /** @return array<string, string|null>|null */
    private function conversionBatch(string $companyId): ?array
    {
        $batch = $this->openingBalances->current($companyId);

        return $batch === null ? null : [
            'id' => $batch->id,
            'source_key' => $batch->sourceKey,
            'source_system' => $batch->sourceSystem,
            'cutover_date' => $batch->cutoverDate,
            'ledger_start_date' => $batch->ledgerStartDate,
            'history_granularity' => $batch->historyGranularity,
            'journal_id' => $batch->journalId,
            'journal_number' => $batch->journalNumber,
        ];
    }

    /**
     * @param  list<TrialBalanceRow>  $after
     * @param  list<TrialBalanceRow>  $before  the same range without the adjustment journals
     * @param  list<string>  $adjustmentKeys
     * @param  int<0, max>  $scale
     * @return array{adjustment_keys: list<string>, balanced: bool, totals: array<string, string>, rows: list<array<string, string>>}
     */
    private function workpaper(array $after, array $before, array $adjustmentKeys, int $scale): array
    {
        $accounts = [];
        $beforeNet = $afterNet = [];

        foreach ($before as $row) {
            $accounts[$row->accountId] = $row;
            $beforeNet[$row->accountId] = BigDecimal::of($row->closingDebit)->minus($row->closingCredit);
        }

        foreach ($after as $row) {
            $accounts[$row->accountId] = $row;
            $afterNet[$row->accountId] = BigDecimal::of($row->closingDebit)->minus($row->closingCredit);
        }

        uasort($accounts, fn (TrialBalanceRow $a, TrialBalanceRow $b): int => strcmp($a->code, $b->code));

        $totals = array_fill_keys(['before_debit', 'before_credit', 'adjustment_debit', 'adjustment_credit', 'after_debit', 'after_credit'], BigDecimal::zero());
        $rows = [];

        foreach ($accounts as $id => $account) {
            $start = $beforeNet[$id] ?? BigDecimal::zero();
            $end = $afterNet[$id] ?? BigDecimal::zero();
            $adjustment = $end->minus($start);

            $cells = [
                'before_debit' => $start->isPositive() ? $start : BigDecimal::zero(),
                'before_credit' => $start->isNegative() ? $start->negated() : BigDecimal::zero(),
                'adjustment_debit' => $adjustment->isPositive() ? $adjustment : BigDecimal::zero(),
                'adjustment_credit' => $adjustment->isNegative() ? $adjustment->negated() : BigDecimal::zero(),
                'after_debit' => $end->isPositive() ? $end : BigDecimal::zero(),
                'after_credit' => $end->isNegative() ? $end->negated() : BigDecimal::zero(),
            ];

            foreach ($cells as $column => $value) {
                $totals[$column] = $totals[$column]->plus($value);
            }

            $rows[] = ['account_id' => $id, 'code' => $account->code, 'name' => $account->name, 'class' => $account->class->value, ...array_map(fn (BigDecimal $value): string => $this->format($value, $scale), $cells)];
        }

        return [
            'adjustment_keys' => $adjustmentKeys,
            'balanced' => $totals['before_debit']->isEqualTo($totals['before_credit'])
                && $totals['adjustment_debit']->isEqualTo($totals['adjustment_credit'])
                && $totals['after_debit']->isEqualTo($totals['after_credit']),
            'totals' => array_map(fn (BigDecimal $total): string => $this->format($total, $scale), $totals),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{account_id: string, code: string, name: string, class: string, opening_debit: BigDecimal, opening_credit: BigDecimal, debit: BigDecimal, credit: BigDecimal, closing_debit: BigDecimal, closing_credit: BigDecimal}
     */
    private function row(TrialBalanceRow $row): array
    {
        return [
            'account_id' => $row->accountId,
            'code' => $row->code,
            'name' => $row->name,
            'class' => $row->class->value,
            'opening_debit' => BigDecimal::of($row->openingDebit),
            'opening_credit' => BigDecimal::of($row->openingCredit),
            'debit' => BigDecimal::of($row->debit),
            'credit' => BigDecimal::of($row->credit),
            'closing_debit' => BigDecimal::of($row->closingDebit),
            'closing_credit' => BigDecimal::of($row->closingCredit),
        ];
    }

    /**
     * @param  int<0, max>  $scale
     */
    private function format(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
