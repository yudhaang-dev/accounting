<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\MasterData\Contracts\Currencies;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Cash flow by the direct method (RP-FR-09): the counter lines of every journal that touches a
 * `cash_equivalents` account are classified by a movement classification, so one payment can fall
 * into several rows and a transfer between cash accounts nets to zero. The result reconciles the
 * opening balance, the net increase and the closing balance of the cash accounts (RP-AC-02); the
 * opening balance import is shown on its own because it is neither an inflow nor an outflow.
 */
final class DirectCashFlow
{
    public function __construct(
        private readonly MovementClassifier $classifier,
        private readonly CashEquivalents $cash,
        private readonly ReportLedger $ledger,
        private readonly Currencies $currencies,
    ) {}

    /**
     * @param  array{rows: list<array{key: string, label: string}>, rules: list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>}  $structure
     * @return array<string, mixed> the classifier result with `inflow` and `outflow` per row, `mapping` and `cash`
     *
     * @throws ValidationException when the `cash_equivalents` role is not mapped
     */
    public function __invoke(string $companyId, array $structure, string $from, string $to, ?DataScope $scope = null, ?ReportFilter $filter = null): array
    {
        $mapping = $this->cash->current($companyId, $to);

        if ($mapping === null || $mapping['account_ids'] === []) {
            throw ValidationException::withMessages(['cash_equivalents' => [__('reports.cash_mapping_missing')]]);
        }

        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter;
        $ids = $mapping['account_ids'];
        $result = ($this->classifier)($companyId, $structure, $from, $to, $scope, $filter, $ids);
        $slice = $this->ledger->slice($companyId, $filter, $scope);

        $all = $this->totals($slice->trialBalance($from, $to), $ids);
        $withoutImport = $this->totals($slice->trialBalance($from, $to, [SystemTransactionKeys::OPENING_BALANCE]), $ids);
        $imported = $all['movement']->minus($withoutImport['movement']);
        $increase = BigDecimal::of($result['total']['credit'])->minus($result['total']['debit']);
        $difference = $all['closing']->minus($all['opening'])->minus($imported)->minus($increase);
        $scale = $this->currencies->functionalScale($companyId);

        $result['rows'] = array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'inflow' => $row['credit'],
            'outflow' => $row['debit'],
            'net' => $this->format(BigDecimal::of($row['credit'])->minus($row['debit']), $scale),
        ], $result['rows']);
        unset($result['total']);

        return [
            ...$result,
            'mapping' => ['version' => $mapping['version'], 'account_ids' => $ids, 'problems' => $this->cash->problems($companyId, $ids)],
            'cash' => [
                'opening_balance' => $this->format($all['opening'], $scale),
                'imported_opening_balance' => $this->format($imported, $scale),
                'net_increase' => $this->format($increase, $scale),
                'closing_balance' => $this->format($all['closing'], $scale),
                'difference' => $this->format($difference, $scale),
                'reconciled' => $difference->toScale($scale, RoundingMode::HalfUp)->isZero(),
            ],
        ];
    }

    /**
     * @param  list<TrialBalanceRow>  $rows
     * @param  list<string>  $cashAccountIds
     * @return array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal}
     */
    private function totals(array $rows, array $cashAccountIds): array
    {
        $total = ['opening' => BigDecimal::zero(), 'movement' => BigDecimal::zero(), 'closing' => BigDecimal::zero()];

        foreach ($rows as $row) {
            if (! in_array($row->accountId, $cashAccountIds, true)) {
                continue;
            }

            $total['opening'] = $total['opening']->plus($row->openingDebit)->minus($row->openingCredit);
            $total['movement'] = $total['movement']->plus($row->debit)->minus($row->credit);
            $total['closing'] = $total['closing']->plus($row->closingDebit)->minus($row->closingCredit);
        }

        return $total;
    }

    /** @param  int<0, max>  $scale */
    private function format(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
