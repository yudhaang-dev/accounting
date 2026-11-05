<?php

namespace App\Modules\Reporting\Http;

use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\Reporting\Application\GeneralLedgerReport;
use App\Modules\Reporting\Application\HistoryCoverage;
use App\Modules\Reporting\Application\ReportEngine;
use App\Modules\Reporting\Application\TrialBalance;
use App\Platform\Export\ExportColumn;
use App\Platform\Export\ExportRow;
use App\Platform\Export\ExportTable;

/**
 * Maps the results of the reports to the table every export format reads (ADR-0018, X-FR-02): the
 * same numbers as the JSON result, under a heading that says whose they are, for which period, and
 * what limits them.
 */
final class ReportTables
{
    /**
     * @param  array<string, mixed>  $result  result of {@see ReportEngine}
     */
    public static function definition(string $companyId, string $name, array $result): ExportTable
    {
        /** @var list<array{key: string, label: string}> $columns */
        $columns = $result['columns'];
        /** @var list<array{key: string, label: string, type: string, values: array<string, string|null>, account_lines?: list<array{code: string, name: string, level: int, is_leaf: bool, values: array<string, string|null>}>}> $rows */
        $rows = $result['rows'];

        $keys = array_column($columns, 'key');
        $values = fn (array $source): array => array_map(fn (string $key): ?string => $source[$key] ?? null, $keys);

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new ExportRow([$row['label'], ...$values($row['values'])], bold: in_array($row['type'], ['heading', 'subtotal', 'current_year_earnings'], true));

            foreach ($row['account_lines'] ?? [] as $line) {
                $lines[] = new ExportRow(["{$line['code']} {$line['name']}", ...$values($line['values'])], bold: ! $line['is_leaf'], muted: $line['is_leaf'], indent: $line['level'] + 1);
            }
        }

        return new ExportTable(
            $name,
            self::meta($companyId, $result),
            [new ExportColumn((string) __('export.row')), ...array_map(fn (array $column): ExportColumn => new ExportColumn($column['label'], numeric: true), $columns)],
            $lines,
        );
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see TrialBalance}
     */
    public static function trialBalance(string $companyId, array $result): ExportTable
    {
        /** @var list<array<string, string>> $rows */
        $rows = $result['rows'];
        /** @var array<string, string> $totals */
        $totals = $result['totals'];

        $money = ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'];

        return new ExportTable(
            (string) __('export.trial_balance'),
            self::meta($companyId, $result),
            [
                new ExportColumn((string) __('export.code')), new ExportColumn((string) __('export.name')),
                ...array_map(fn (string $key): ExportColumn => new ExportColumn((string) __("export.{$key}"), numeric: true), $money),
            ],
            [
                ...array_map(fn (array $row): ExportRow => new ExportRow([$row['code'], $row['name'], ...array_map(fn (string $key): string => $row[$key], $money)]), $rows),
                new ExportRow([null, (string) __($result['restricted'] ? 'export.total_restricted' : 'export.total'), ...array_map(fn (string $key): string => $totals[$key], $money)], bold: true),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see GeneralLedgerReport}
     */
    public static function generalLedger(string $companyId, array $result): ExportTable
    {
        /** @var array{code: string, name: string} $account */
        $account = $result['account'];
        /** @var array{from: string} $parameters */
        $parameters = $result['parameters'];
        /** @var list<array<string, string|null>> $lines */
        $lines = $result['lines'];

        return new ExportTable(
            (string) __('export.general_ledger'),
            self::meta($companyId, $result, [(string) __('export.account', ['account' => "{$account['code']} {$account['name']}"])]),
            [
                new ExportColumn((string) __('export.date')), new ExportColumn((string) __('export.journal')), new ExportColumn((string) __('export.transaction_type')), new ExportColumn((string) __('export.description')),
                new ExportColumn((string) __('export.debit'), numeric: true), new ExportColumn((string) __('export.credit'), numeric: true), new ExportColumn((string) __('export.currency')),
                new ExportColumn((string) __('export.transaction_amount'), numeric: true), new ExportColumn((string) __('export.exchange_rate'), numeric: true), new ExportColumn((string) __('export.balance'), numeric: true),
            ],
            [
                new ExportRow([$parameters['from'], null, null, (string) __('export.opening_balance'), null, null, null, null, null, (string) $result['opening_balance']], bold: true),
                ...array_map(fn (array $line): ExportRow => new ExportRow([
                    $line['accounting_date'], $line['journal_number'], $line['transaction_type_key'], $line['description'],
                    $line['debit'], $line['credit'], $line['currency'], $line['transaction_amount'], $line['exchange_rate'], $line['balance'],
                ]), $lines),
                new ExportRow([null, null, null, (string) __('export.closing_balance'), null, null, null, null, null, (string) $result['closing_balance']], bold: true),
            ],
        );
    }

    /**
     * The lines under the title: company, period, currency, the filter that is on, and what limits the numbers.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $extra  lines of the report itself, after the company
     * @return list<string>
     */
    private static function meta(string $companyId, array $result, array $extra = []): array
    {
        /** @var array{from: string, to: string, dimensions?: array<string, string>, transaction_type_keys?: list<string>, source?: string|null} $parameters */
        $parameters = $result['parameters'];

        $dimensions = $parameters['dimensions'] ?? [];
        $keys = $parameters['transaction_type_keys'] ?? [];
        $filters = array_values(array_filter([
            ...array_map(fn (string $type, string $id): string => "{$type}: {$id}", array_keys($dimensions), $dimensions),
            $keys === [] ? null : implode(', ', $keys),
            $parameters['source'] ?? null,
        ]));

        return array_values(array_filter([
            app(CompanyDirectory::class)->find($companyId)?->name,
            ...$extra,
            $parameters['from'] === $parameters['to']
                ? (string) __('export.as_of', ['date' => $parameters['to']])
                : (string) __('export.period', ['from' => $parameters['from'], 'to' => $parameters['to']]),
            (string) __('export.currency_line', ['currency' => (string) $result['functional_currency']]),
            $filters === [] ? null : (string) __('export.filter', ['filters' => implode('; ', $filters)]),
            ($result['restricted'] ?? false) === true ? (string) __('export.restricted') : null,
            ($result['coverage_status'] ?? HistoryCoverage::COMPLETE) === HistoryCoverage::COMPLETE ? null : (string) __('export.coverage', ['status' => (string) $result['coverage_status']]),
        ], fn (?string $line): bool => $line !== null && $line !== ''));
    }
}
