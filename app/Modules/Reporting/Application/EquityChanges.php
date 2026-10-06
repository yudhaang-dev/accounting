<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Statement of changes in equity (RP-FR-10): a matrix of equity components (account sets, which may
 * include income and expense accounts for the result of the year) against the rows of a movement
 * classification. Each component starts from its opening balance, adds the classified movement of
 * the range and ends at its closing balance. The opening balance import counts as opening, not as
 * movement, as in the cash flow. Equity and result accounts that no component claims go to the
 * `unassigned` component and movement that no rule matches to the `unclassified` row, so nothing is
 * dropped; the closing total is checked against the ledger itself, which is what the balance sheet
 * shows as equity (RP-AC-09). Amounts are credit-positive.
 */
final class EquityChanges
{
    public function __construct(
        private readonly MovementClassifier $classifier,
        private readonly ReportLedger $ledger,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
    ) {}

    /**
     * @param  array{rows: list<array{key: string, label: string}>, rules: list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>, components?: list<array{key: string, label: string, accounts: list<array<string, string>>}>}  $structure
     * @return array<string, mixed>
     *
     * @throws ValidationException when the classification has no components
     */
    public function __invoke(string $companyId, array $structure, string $from, string $to, ?DataScope $scope = null, ?ReportFilter $filter = null): array
    {
        if (($structure['components'] ?? []) === []) {
            throw ValidationException::withMessages(['components' => [__('reports.equity_components_missing')]]);
        }

        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter;
        $chart = $this->accounts->all($companyId);
        $byId = [];

        foreach ($chart as $account) {
            $byId[$account->id] = $account;
        }

        $componentOf = [];

        foreach ($structure['components'] as $component) {
            foreach (ReportStructure::resolve($component['accounts'], $chart) as $id) {
                $componentOf[$id] ??= $component['key'];
            }
        }

        $slice = $this->ledger->slice($companyId, $filter, $scope);
        $all = $slice->trialBalance($from, $to);
        $kept = [];

        foreach ($slice->trialBalance($from, $to, [SystemTransactionKeys::OPENING_BALANCE]) as $row) {
            $kept[$row->accountId] = BigDecimal::of($row->debit)->minus($row->credit);
        }

        $zero = BigDecimal::zero();
        $opening = $closing = $ledgerEquity = [];
        $ledgerTotal = $zero;
        $used = [];

        foreach ($all as $row) {
            $isResultSide = in_array($row->class, [AccountClass::Equity, AccountClass::Income, AccountClass::Expense], true);
            $component = $componentOf[$row->accountId] ?? ($isResultSide ? ClassificationStructure::UNASSIGNED : null);
            $end = BigDecimal::of($row->closingDebit)->minus($row->closingCredit);

            if ($isResultSide) {
                $ledgerTotal = $ledgerTotal->minus($end);
            }

            if ($component === null) {
                continue;
            }

            $movement = BigDecimal::of($row->debit)->minus($row->credit);
            $start = BigDecimal::of($row->openingDebit)->minus($row->openingCredit)->plus($movement->minus($kept[$row->accountId] ?? $zero));
            $opening[$component] = ($opening[$component] ?? $zero)->plus($start);
            $closing[$component] = ($closing[$component] ?? $zero)->plus($end);
            $used[$component] = true;
            $ledgerEquity[$row->accountId] = true;
        }

        $rowOf = $this->classifier->rowOf($structure, $chart);
        $matrix = [];
        $unclassified = [];

        foreach ($slice->movementByTransactionType($from, $to) as $line) {
            $component = $componentOf[$line->accountId] ?? (isset($ledgerEquity[$line->accountId]) ? ClassificationStructure::UNASSIGNED : null);

            if ($component === null || $line->transactionTypeKey === SystemTransactionKeys::OPENING_BALANCE) {
                continue;
            }

            $row = $rowOf($line->accountId, $line->transactionTypeKey);
            $matrix[$row][$component] = ($matrix[$row][$component] ?? $zero)->plus($line->debit)->minus($line->credit);

            if ($row === ClassificationStructure::UNCLASSIFIED) {
                $unclassified["{$line->accountId}|{$line->transactionTypeKey}"] = ['account_id' => $line->accountId, 'code' => $byId[$line->accountId]->code ?? '', 'name' => $byId[$line->accountId]->name ?? '', 'transaction_type_key' => $line->transactionTypeKey];
            }
        }

        $scale = $this->currencies->functionalScale($companyId);
        $components = $structure['components'];

        if (isset($used[ClassificationStructure::UNASSIGNED])) {
            $components[] = ['key' => ClassificationStructure::UNASSIGNED, 'label' => (string) __('reports.unassigned_component')];
        }

        $rows = $structure['rows'];

        if (isset($matrix[ClassificationStructure::UNCLASSIFIED])) {
            $rows[] = ['key' => ClassificationStructure::UNCLASSIFIED, 'label' => (string) __('reports.unclassified_row')];
        }

        $totals = [];
        $outRows = [];

        foreach ($rows as $row) {
            $values = [];
            $total = $zero;

            foreach ($components as $component) {
                $value = ($matrix[$row['key']][$component['key']] ?? $zero)->negated();
                $values[$component['key']] = $this->format($value, $scale);
                $total = $total->plus($value);
                $totals[$component['key']] = ($totals[$component['key']] ?? $zero)->plus($value);
            }

            $outRows[] = ['key' => $row['key'], 'label' => $row['label'], 'values' => $values, 'total' => $this->format($total, $scale)];
        }

        $outComponents = [];
        $componentsTotal = $zero;
        $reconciled = true;

        foreach ($components as $component) {
            $start = ($opening[$component['key']] ?? $zero)->negated();
            $end = ($closing[$component['key']] ?? $zero)->negated();
            $isReconciled = $start->plus($totals[$component['key']] ?? $zero)->toScale($scale, RoundingMode::HalfUp)->isEqualTo($end->toScale($scale, RoundingMode::HalfUp));
            $reconciled = $reconciled && $isReconciled;
            $componentsTotal = $componentsTotal->plus($end);

            $outComponents[] = [
                'key' => $component['key'],
                'label' => $component['label'],
                'opening_balance' => $this->format($start, $scale),
                'movement' => $this->format($totals[$component['key']] ?? $zero, $scale),
                'closing_balance' => $this->format($end, $scale),
                'reconciled' => $isReconciled,
            ];
        }

        $difference = $componentsTotal->minus($ledgerTotal);

        return [
            'parameters' => ['from' => $from, 'to' => $to, ...$filter->toArray()],
            'functional_currency' => $company->functionalCurrency,
            'restricted' => $scope->isRestricted(),
            'components' => $outComponents,
            'rows' => $outRows,
            'unclassified' => array_values($unclassified),
            'equity' => [
                'components_total' => $this->format($componentsTotal, $scale),
                'ledger_total' => $this->format($ledgerTotal, $scale),
                'difference' => $this->format($difference, $scale),
                'reconciled' => $reconciled,
                'balanced' => $difference->toScale($scale, RoundingMode::HalfUp)->isZero(),
            ],
        ];
    }

    /** @param  int<0, max>  $scale */
    private function format(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
