<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

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
    ) {}

    /**
     * `$from`..`to` is the period of `period` columns, `to` the date of `as_of` columns and the end of
     * `ytd` columns. A restricted `$scope` limits the lines that are added up, and the result says so
     * with `restricted` (IA-FR-03).
     *
     * @param  array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>}  $structure
     * @return array{parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, unmapped_accounts: list<array{account_id: string, code: string, name: string}>}
     */
    public function __invoke(string $companyId, array $structure, string $from, string $to, ?DataScope $scope = null, ?ReportFilter $filter = null): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter;
        $slice = $this->ledger->slice($companyId, $filter, $scope);

        $chart = $this->accounts->all($companyId);
        $claims = $this->claims($structure['rows'], $chart);

        $columns = [];
        $values = [];
        $unmapped = [];

        foreach ($structure['columns'] as $column) {
            if ($column['kind'] === 'difference') {
                continue;
            }

            $range = $this->range($companyId, $column, $from, $to);
            $columns[$column['key']] = ['key' => $column['key'], 'label' => $column['label'], 'kind' => $column['kind'], 'from' => $range[0] ?? null, 'to' => $range[1] ?? null, 'available' => $range !== null];

            if ($range === null) {
                continue;
            }

            $balances = $this->balances($slice->trialBalance($range[0], $range[1]));
            $values[$column['key']] = $this->evaluate($structure, $claims, $balances, $unmapped);
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
        ];
    }

    /**
     * Which row claims each posting account. The first row that names an account keeps it; validation
     * refuses definitions where two rows claim one, so this only matters when the chart changed
     * after the version was saved.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<AccountData>  $chart
     * @return array<string, string> account ID => row key
     */
    private function claims(array $rows, array $chart): array
    {
        $claims = [];

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
     * @param  list<TrialBalanceRow>  $rows
     * @return array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal}>
     */
    private function balances(array $rows): array
    {
        $balances = [];

        foreach ($rows as $row) {
            $balances[$row->accountId] = [
                'opening' => BigDecimal::of($row->openingDebit)->minus($row->openingCredit),
                'movement' => BigDecimal::of($row->debit)->minus($row->credit),
                'closing' => BigDecimal::of($row->closingDebit)->minus($row->closingCredit),
            ];
        }

        return $balances;
    }

    /**
     * Values of every row for one column, debit positive until a row's own sign is applied.
     *
     * @param  array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>}  $structure
     * @param  array<string, string>  $claims
     * @param  array<string, array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal}>  $balances
     * @param  array<string, true>  $unmapped  receives the accounts that no row claims
     * @return array<string, BigDecimal|null> row key => value
     */
    private function evaluate(array $structure, array $claims, array $balances, array &$unmapped): array
    {
        $unmappedValue = BigDecimal::zero();

        foreach ($balances as $id => $balance) {
            if (! isset($claims[$id])) {
                $unmapped[$id] = true;
                $unmappedValue = $unmappedValue->plus($this->pick($balance, $structure['basis']));
            }
        }

        $values = [ReportStructure::UNMAPPED => $unmappedValue];
        $perRow = [];

        foreach ($balances as $id => $balance) {
            if (isset($claims[$id])) {
                $perRow[$claims[$id]][] = $balance;
            }
        }

        foreach ($structure['rows'] as $row) {
            $values[$row['key']] = match ($row['type']) {
                'heading' => null,
                'accounts' => $this->signed($row['sign'], $this->sum($perRow[$row['key']] ?? [], $row['basis'])),
                default => $this->subtotal($row, $values),
            };
        }

        return $values;
    }

    /**
     * @param  list<array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal}>  $balances
     */
    private function sum(array $balances, string $basis): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($balances as $balance) {
            $total = $total->plus($this->pick($balance, $basis));
        }

        return $total;
    }

    /** @param  array{opening: BigDecimal, movement: BigDecimal, closing: BigDecimal}  $balance */
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
