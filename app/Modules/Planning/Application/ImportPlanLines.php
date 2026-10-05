<?php

namespace App\Modules\Planning\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Domain\PlanVersion;
use Illuminate\Validation\ValidationException;

/**
 * Enters plan lines from CSV text (PL-FR-03). The first line is a header with the columns `period` (any
 * date inside the period), `account_code` and `amount`, optionally `note`, and one column
 * `dimension.<type key>` with the code of the value for each dimension type used. The import is all or
 * nothing: if any row is invalid nothing is recorded and every problem is reported with its line (PL-AC-02).
 */
final class ImportPlanLines
{
    public const MAX_ROWS = 5000;

    private const REQUIRED = ['period', 'account_code', 'amount'];

    private const DIMENSION_PREFIX = 'dimension.';

    public function __construct(
        private readonly ManagePlanLines $lines,
        private readonly AccountDirectory $accounts,
        private readonly PeriodDirectory $periods,
        private readonly DimensionSets $dimensionSets,
    ) {}

    /**
     * @return array{created: int, updated: int}
     *
     * @throws ValidationException
     */
    public function __invoke(string $versionId, string $csv, int $actorId): array
    {
        $companyId = PlanVersion::query()->findOrFail($versionId)->company_id;
        ['header' => $header, 'rows' => $cells] = $this->parse($csv, $companyId);

        $errors = [];
        $rows = [];

        $valueIds = [];

        foreach ($header as $column) {
            if (str_starts_with($column, self::DIMENSION_PREFIX)) {
                $key = substr($column, strlen(self::DIMENSION_PREFIX));
                $wanted = array_values(array_unique(array_filter(array_map(fn (array $row): string => $row['dimension'][$key] ?? '', $cells))));
                $valueIds[$key] = $this->dimensionSets->valueIdsByCode($companyId, $key, $wanted);
            }
        }

        $accounts = [];
        $periods = [];

        foreach ($cells as $line => $cell) {
            $dimensions = [];
            $failed = false;

            $period = $this->validDate($cell['period']) ? ($periods[$cell['period']] ??= $this->periods->forDate($companyId, $cell['period'])) : null;
            $account = $accounts[$cell['account_code']] ??= $this->accounts->findByCode($companyId, $cell['account_code']);

            if ($period === null) {
                $errors["rows.{$line}.period"] = [__('plans.csv_period_unknown', ['value' => $cell['period']])];
                $failed = true;
            }

            if ($account === null) {
                $errors["rows.{$line}.account_code"] = [__('plans.csv_account_unknown', ['value' => $cell['account_code']])];
                $failed = true;
            }

            foreach ($cell['dimension'] as $key => $code) {
                if ($code === '') {
                    continue;
                }

                if (! isset($valueIds[$key][$code])) {
                    $errors["rows.{$line}.dimensions.{$key}"] = [__('plans.csv_dimension_unknown', ['value' => $code])];
                    $failed = true;

                    continue;
                }

                $dimensions[$key] = $valueIds[$key][$code];
            }

            if (! $failed) {
                $rows[$line] = [
                    'period_id' => $period?->id,
                    'account_id' => $account?->id,
                    'amount' => $cell['amount'],
                    'note' => $cell['note'],
                    'dimensions' => $dimensions,
                ];
            }
        }

        return $this->lines->enter($versionId, $rows, $actorId, 'rows', $errors, 'plan_version.lines_imported');
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * @return array{header: list<string>, rows: array<int, array{period: string, account_code: string, amount: string, note: string, dimension: array<string, string>}>} rows keyed by CSV line number
     *
     * @throws ValidationException
     */
    private function parse(string $csv, string $companyId): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];

        $header = array_map(fn (?string $name): string => strtolower(trim((string) $name)), str_getcsv((string) array_shift($lines), escape: ''));

        if (array_diff(self::REQUIRED, $header) !== []) {
            throw ValidationException::withMessages(['file' => [__('plans.csv_header', ['columns' => implode(', ', self::REQUIRED)])]]);
        }

        $dimensionKeys = $this->dimensionSets->activeTypeKeys($companyId);

        foreach ($header as $column) {
            $known = in_array($column, [...self::REQUIRED, 'note'], true)
                || (str_starts_with($column, self::DIMENSION_PREFIX) && in_array(substr($column, strlen(self::DIMENSION_PREFIX)), $dimensionKeys, true));

            if (! $known) {
                throw ValidationException::withMessages(['file' => [__('plans.csv_column_unknown', ['column' => $column])]]);
            }
        }

        $rows = [];

        foreach ($lines as $index => $text) {
            if (trim($text) === '') {
                continue;
            }

            $values = str_getcsv($text, escape: '');
            $cell = fn (string $column): string => trim((string) ($values[array_search($column, $header, true)] ?? ''));

            $dimension = [];

            foreach ($header as $column) {
                if (str_starts_with($column, self::DIMENSION_PREFIX)) {
                    $dimension[substr($column, strlen(self::DIMENSION_PREFIX))] = $cell($column);
                }
            }

            $rows[$index + 2] = [
                'period' => $cell('period'),
                'account_code' => $cell('account_code'),
                'amount' => $cell('amount'),
                'note' => in_array('note', $header, true) ? $cell('note') : '',
                'dimension' => $dimension,
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => [__('plans.csv_empty')]]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => [__('plans.csv_too_many', ['max' => self::MAX_ROWS])]]);
        }

        return ['header' => $header, 'rows' => $rows];
    }
}
