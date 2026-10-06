<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Evaluates a report definition version against the ledger (RP-FR-01, RP-FR-02). Rows pick accounts
 * by selector and a value basis; subtotals add other rows with a sign; columns choose the date range.
 * Posted accounts that no row claims are not dropped: they land in the `unmapped` row so that the
 * report still adds up to the ledger (RP-AC-07). A column whose range is not covered by the fiscal
 * calendar has no value at all, never zero.
 */
final class ReportEngine
{
    public function __construct(
        private readonly ReportLedger $ledger,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
        private readonly PeriodDirectory $periods,
        private readonly CashEquivalents $cash,
    ) {}

    /**
     * `$from`..`to` is the period of `period` columns, `to` the date of `as_of` columns and the end of
     * `ytd` columns. A restricted `$scope` limits the lines that are added up, and the result says so
     * with `restricted` (IA-FR-03).
     *
     * @param  array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>}  $structure
     * @return array{parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, unmapped_accounts: list<array{account_id: string, code: string, name: string}>}
     */
    public function __invoke(string $companyId, array $structure, string $from, string $to, ?DataScope $scope = null, ?ReportFilter $filter = null, string $kind = ReportStructure::CUSTOM): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter;
        $slice = $this->ledger->slice($companyId, $filter, $scope);

        $chart = $this->accounts->all($companyId);
        $cashIds = $this->cashAccountIds($companyId, $structure['rows'], $to);
        $claims = $this->claims($structure['rows'], $chart, $cashIds);

        $columns = [];
        $values = [];
        $unmapped = [];
        $checks = [];
        $cashChecks = [];

        foreach ($structure['columns'] as $column) {
            if ($column['kind'] === 'difference') {
                continue;
            }

            $range = $this->range($companyId, $column, $from, $to);
            $columns[$column['key']] = ['key' => $column['key'], 'label' => $column['label'], 'kind' => $column['kind'], 'from' => $range[0] ?? null, 'to' => $range[1] ?? null, 'available' => $range !== null];

            if ($range === null) {
                continue;
            }

            $excluded = $this->excludedKeys($kind, $column['kind']);
            $balances = $this->balances($slice->trialBalance($range[0], $range[1], $excluded), $kind === ReportStructure::INCOME_STATEMENT);

            if ($kind === ReportStructure::CASH_FLOW_INDIRECT && $cashIds !== []) {
                $balances = $this->withImportInOpeningCash($balances, $slice->trialBalance($range[0], $range[1]), $cashIds);
                $cashChecks[$column['key']] = $this->cashCheck($balances, $cashIds);
            }

            $values[$column['key']] = $this->evaluate($structure, $claims, $balances, $unmapped);

            if ($kind === ReportStructure::BALANCE_SHEET && $column['kind'] === 'as_of') {
                $checks[$column['key']] = $this->balanceCheck($balances);
            }
        }

        foreach ($structure['columns'] as $column) {
            if ($column['kind'] !== 'difference') {
                continue;
            }

            $available = isset($values[$column['minuend']], $values[$column['subtrahend']]);
            $columns[$column['key']] = ['key' => $column['key'], 'label' => $column['label'], 'kind' => 'difference', 'from' => null, 'to' => null, 'available' => $available];
            $values[$column['key']] = $this->difference($structure['rows'], $values[$column['minuend']] ?? null, $values[$column['subtrahend']] ?? null, $unmapped !== []);
        }

        $scale = $this->currencies->functionalScale($companyId);
        $rows = [];

        foreach ($this->rowsWithUnmapped($structure['rows'], $unmapped !== []) as $row) {
            $cells = [];

            foreach ($structure['columns'] as $column) {
                $value = $values[$column['key']][$row['key']] ?? null;
                $cells[$column['key']] = $value === null ? null : $this->format($value, $scale);
            }

            $rows[] = ['key' => $row['key'], 'label' => $row['label'], 'type' => $row['type'], 'values' => $cells];
        }

        $accountsById = [];

        foreach ($chart as $account) {
            $accountsById[$account->id] = $account;
        }

        $list = [];

        foreach ($unmapped as $id => $_) {
            $list[] = ['account_id' => $id, 'code' => $accountsById[$id]->code ?? '', 'name' => $accountsById[$id]->name ?? ''];
        }

        usort($list, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return [
            'parameters' => ['from' => $from, 'to' => $to, ...$filter->toArray()],
            'functional_currency' => $company->functionalCurrency,
            'restricted' => $scope->isRestricted(),
            'columns' => array_map(fn (array $column): array => $columns[$column['key']], $structure['columns']),
            'rows' => $rows,
            'unmapped_accounts' => $list,
            ...($kind === ReportStructure::BALANCE_SHEET ? ['balance_checks' => $this->formatChecks($checks, $scale)] : []),
            ...($kind === ReportStructure::CASH_FLOW_INDIRECT ? ['cash_checks' => array_map(fn (array $check): array => [
                'opening_balance' => $this->format($check['opening'], $scale),
                'net_increase' => $this->format($check['movement'], $scale),
                'closing_balance' => $this->format($check['closing'], $scale),
                'reconciled' => $check['opening']->plus($check['movement'])->toScale($scale, RoundingMode::HalfUp)->isEqualTo($check['closing']->toScale($scale, RoundingMode::HalfUp)),
            ], $cashChecks)] : []),
        ];
    }

    /**
     * The accounts of the `cash_equivalents` role in force on `$to`, when a row asks for them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     *
     * @throws ValidationException when a row needs the role and it is not mapped
     */
    private function cashAccountIds(string $companyId, array $rows, string $to): array
    {
        if (! in_array('cash_equivalents', array_column($rows, 'type'), true)) {
            return [];
        }

        $mapping = $this->cash->current($companyId, $to);

        if ($mapping === null || $mapping['account_ids'] === []) {
            throw ValidationException::withMessages(['cash_equivalents' => [__('reports.cash_mapping_missing')]]);
        }

        return $mapping['account_ids'];
    }

    /**
     * Cash accounts keep the opening balance import in their opening balance: it is where the cash
     * of a company that starts mid-history comes from, not an increase of the period, while the
     * other accounts leave it out of the movement (RP-FR-09).
     *
     * @param  array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>  $balances
     * @param  list<TrialBalanceRow>  $full  the same range with nothing left out
     * @param  list<string>  $cashIds
     * @return array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>
     */
    private function withImportInOpeningCash(array $balances, array $full, array $cashIds): array
    {
        foreach ($full as $row) {
            if (! in_array($row->accountId, $cashIds, true)) {
                continue;
            }

            $movement = BigDecimal::of($row->debit)->minus($row->credit);
            $kept = $balances[$row->accountId]['movement'] ?? BigDecimal::zero();

            $balances[$row->accountId] = [
                'opening' => BigDecimal::of($row->openingDebit)->minus($row->openingCredit)->plus($movement->minus($kept)),
                'movement' => $kept,
                'closing' => BigDecimal::of($row->closingDebit)->minus($row->closingCredit),
                'class' => $row->class,
            ];
        }

        return $balances;
    }

    /**
     * @param  array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>  $balances
     * @param  list<string>  $cashIds
     * @return array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal}
     */
    private function cashCheck(array $balances, array $cashIds): array
    {
        $total = ['opening' => BigDecimal::zero(), 'movement' => BigDecimal::zero(), 'closing' => BigDecimal::zero()];

        foreach ($cashIds as $id) {
            foreach (['opening', 'movement', 'closing'] as $part) {
                $total[$part] = $total[$part]->plus($balances[$id][$part] ?? BigDecimal::zero());
            }
        }

        return $total;
    }

    /**
     * @param  array<string, array{assets: BigDecimal, liabilities_and_equity: BigDecimal, difference: BigDecimal}>  $checks
     * @param  int<0, max>  $scale
     * @return array<string, array{assets: string, liabilities_and_equity: string, difference: string, balanced: bool}>
     */
    private function formatChecks(array $checks, int $scale): array
    {
        return array_map(fn (array $check): array => [
            'assets' => $this->format($check['assets'], $scale),
            'liabilities_and_equity' => $this->format($check['liabilities_and_equity'], $scale),
            'difference' => $this->format($check['difference'], $scale),
            'balanced' => $check['difference']->toScale($scale, RoundingMode::HalfUp)->isZero(),
        ], $checks);
    }

    /**
     * Which row claims each posting account. The first row that names an account keeps it; validation
     * refuses definitions where two rows claim one, so this only matters when the chart changed
     * after the version was saved.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<AccountData>  $chart
     * @param  list<string>  $cashIds  claimed by the `cash_equivalents` row before any other row
     * @return array<string, string> account ID => row key
     */
    private function claims(array $rows, array $chart, array $cashIds = []): array
    {
        $claims = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'cash_equivalents') {
                foreach ($cashIds as $id) {
                    $claims[$id] ??= $row['key'];
                }
            }
        }

        foreach ($rows as $row) {
            if ($row['type'] !== 'accounts') {
                continue;
            }

            foreach (ReportStructure::resolve($row['accounts'], $chart) as $id) {
                $claims[$id] ??= $row['key'];
            }
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array{0: string, 1: string}|null null when the fiscal calendar does not cover the range
     */
    private function range(string $companyId, array $column, string $from, string $to): ?array
    {
        $shift = $column['shift'] ?? ['years' => 0, 'months' => 0];
        $end = $this->shift($to, $shift['years'], $shift['months']);

        if ($column['kind'] === 'as_of') {
            return [$end, $end];
        }

        if ($column['kind'] === 'ytd') {
            $period = $this->periods->forDate($companyId, $end);
            $year = $period === null ? null : $this->periods->fiscalYear($companyId, $period->fiscalYearId);

            return $year === null ? null : [$year->startDate, $end];
        }

        return [$this->shift($from, $shift['years'], $shift['months']), $end];
    }

    /** A month end stays a month end after the shift, so 28 February 2026 minus a year is 28 February 2025 and 29 February 2024 minus a year is 28 February 2023. */
    private function shift(string $date, int $years, int $months): string
    {
        $moment = Carbon::parse($date);
        $monthEnd = $moment->isLastOfMonth();
        $shifted = $moment->copy()->startOfMonth()->addYearsNoOverflow($years)->addMonthsNoOverflow($months);

        return ($monthEnd ? $shifted->endOfMonth() : $shifted->day(min($moment->day, $shifted->daysInMonth)))->toDateString();
    }

    /**
     * A cash flow by the indirect method leaves out the same journals, so the profit it starts from
     * is the profit of the income statement. An income statement leaves the closing journal out of every column, so a closed year still shows
     * its result, and the opening balance import out of period columns, so a month after a mid-year
     * cutover holds its own movement only; a year to date column starts at the fiscal year and keeps
     * the imported balances (RP-FR-07, RP-AC-05, RP-AC-06).
     *
     * @return list<string>
     */
    private function excludedKeys(string $kind, string $columnKind): array
    {
        if (! in_array($kind, [ReportStructure::INCOME_STATEMENT, ReportStructure::CASH_FLOW_INDIRECT], true)) {
            return [];
        }

        return $columnKind === 'ytd'
            ? [SystemTransactionKeys::CLOSING]
            : [SystemTransactionKeys::CLOSING, SystemTransactionKeys::OPENING_BALANCE];
    }

    /**
     * @param  list<TrialBalanceRow>  $rows
     * @param  bool  $resultAccountsOnly  keeps income and expense accounts, the only ones an income statement adds up
     * @return array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>
     */
    private function balances(array $rows, bool $resultAccountsOnly = false): array
    {
        $balances = [];

        foreach ($rows as $row) {
            if ($resultAccountsOnly && ! in_array($row->class, [AccountClass::Income, AccountClass::Expense], true)) {
                continue;
            }

            $balances[$row->accountId] = [
                'opening' => BigDecimal::of($row->openingDebit)->minus($row->openingCredit),
                'movement' => BigDecimal::of($row->debit)->minus($row->credit),
                'closing' => BigDecimal::of($row->closingDebit)->minus($row->closingCredit),
                'class' => $row->class,
            ];
        }

        return $balances;
    }

    /**
     * Values of every row for one column, debit positive until a row's own sign is applied.
     *
     * @param  array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>}  $structure
     * @param  array<string, string>  $claims
     * @param  array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>  $balances
     * @param  array<string, true>  $unmapped  receives the accounts that no row claims
     * @return array<string, BigDecimal|null> row key => value
     */
    private function evaluate(array $structure, array $claims, array $balances, array &$unmapped): array
    {
        $unmappedValue = BigDecimal::zero();
        $earnings = BigDecimal::zero();
        $hasEarningsRow = in_array('current_year_earnings', array_column($structure['rows'], 'type'), true);

        foreach ($balances as $id => $balance) {
            if (isset($claims[$id])) {
                continue;
            }

            // Income and expense that no row claims are the result of the year so far: a row of its own, once.
            if ($hasEarningsRow && $this->isResultAccount($balance)) {
                $earnings = $earnings->plus($balance['closing']);

                continue;
            }

            $unmapped[$id] = true;
            $unmappedValue = $unmappedValue->plus($this->pick($balance, $structure['basis']));
        }

        $values = [ReportStructure::UNMAPPED => $unmappedValue];
        $perRow = [];

        // Every cash row reads the same accounts, whatever its basis; the first of them holds the claim.
        $cashRowKey = '';

        foreach ($structure['rows'] as $row) {
            if ($row['type'] === 'cash_equivalents') {
                $cashRowKey = $row['key'];

                break;
            }
        }

        foreach ($balances as $id => $balance) {
            if (isset($claims[$id])) {
                $perRow[$claims[$id]][] = $balance;
            }
        }

        foreach ($structure['rows'] as $row) {
            $values[$row['key']] = match ($row['type']) {
                'heading' => null,
                'accounts' => $this->signed($row['sign'], $this->sum($perRow[$row['key']] ?? [], $row['basis'])),
                'current_year_earnings' => $this->signed($row['sign'], $earnings),
                'cash_equivalents' => $this->signed($row['sign'], $this->sum($perRow[$cashRowKey] ?? [], $row['basis'])),
                default => $this->subtotal($row, $values),
            };
        }

        return $values;
    }

    /** @param  array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}  $balance */
    private function isResultAccount(array $balance): bool
    {
        return in_array($balance['class'], [AccountClass::Income, AccountClass::Expense], true);
    }

    /**
     * Assets against liabilities, equity and the result of the year so far, from the ledger itself so
     * that no row definition can hide a difference (RP-FR-08, RP-AC-01).
     *
     * @param  array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>  $balances
     * @return array{assets: BigDecimal, liabilities_and_equity: BigDecimal, difference: BigDecimal}
     */
    private function balanceCheck(array $balances): array
    {
        $assets = BigDecimal::zero();
        $rest = BigDecimal::zero();

        foreach ($balances as $balance) {
            if ($balance['class'] === AccountClass::Asset) {
                $assets = $assets->plus($balance['closing']);
            } else {
                $rest = $rest->plus($balance['closing']);
            }
        }

        $other = $rest->negated();

        return ['assets' => $assets, 'liabilities_and_equity' => $other, 'difference' => $assets->minus($other)];
    }

    /**
     * @param  list<array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}>  $balances
     */
    private function sum(array $balances, string $basis): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($balances as $balance) {
            $total = $total->plus($this->pick($balance, $basis));
        }

        return $total;
    }

    /** @param  array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal, class: AccountClass}  $balance */
    private function pick(array $balance, string $basis): BigDecimal
    {
        return match ($basis) {
            'opening' => $balance['opening'],
            'movement' => $balance['movement'],
            default => $balance['closing'],
        };
    }

    private function signed(string $sign, BigDecimal $value): BigDecimal
    {
        return $sign === 'credit' ? $value->negated() : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, BigDecimal|null>  $values
     */
    private function subtotal(array $row, array $values): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($row['terms'] as $term) {
            $total = $total->plus(($values[$term['row']] ?? BigDecimal::zero())->multipliedBy($term['sign']));
        }

        return $this->signed($row['sign'], $total);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, BigDecimal|null>|null  $minuend
     * @param  array<string, BigDecimal|null>|null  $subtrahend
     * @return array<string, BigDecimal|null>
     */
    private function difference(array $rows, ?array $minuend, ?array $subtrahend, bool $withUnmapped): array
    {
        if ($minuend === null || $subtrahend === null) {
            return [];
        }

        $keys = array_column($rows, 'key');

        if ($withUnmapped) {
            $keys[] = ReportStructure::UNMAPPED;
        }

        $values = [];

        foreach ($keys as $key) {
            $a = $minuend[$key] ?? null;
            $b = $subtrahend[$key] ?? null;
            $values[$key] = $a === null || $b === null ? null : $a->minus($b);
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rowsWithUnmapped(array $rows, bool $withUnmapped): array
    {
        if ($withUnmapped) {
            $rows[] = ['key' => ReportStructure::UNMAPPED, 'label' => (string) __('reports.unmapped_row'), 'type' => 'unmapped'];
        }

        return $rows;
    }

    /** @param  int<0, max>  $scale */
    private function format(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
