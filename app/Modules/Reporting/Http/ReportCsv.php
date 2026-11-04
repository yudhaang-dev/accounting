<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\DirectCashFlow;
use App\Modules\Reporting\Application\EquityChanges;
use App\Modules\Reporting\Application\MovementClassifier;
use App\Modules\Reporting\Application\ReportEngine;
use App\Platform\Export\CsvExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads of the evaluated report definitions and classifications (X-FR-02); the same
 * numbers as the JSON result, one row per report row.
 */
final class ReportCsv
{
    public static function wanted(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'text/csv');
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see ReportEngine}
     */
    public static function definition(string $key, array $result): StreamedResponse
    {
        /** @var list<array{key: string, label: string}> $columns */
        $columns = $result['columns'];
        /** @var list<array{key: string, label: string, type: string, values: array<string, string|null>, account_lines?: list<array{code: string, name: string, is_leaf: bool, values: array<string, string|null>}>}> $rows */
        $rows = $result['rows'];
        /** @var array{from: string, to: string} $parameters */
        $parameters = $result['parameters'];

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = [
                $row['key'], $row['label'], $row['type'],
                ...array_map(fn (array $column): string => $row['values'][$column['key']] ?? '', $columns),
            ];

            // The accounts of a detailed row follow it, typed apart so that a sum over the rows is not doubled.
            foreach ($row['account_lines'] ?? [] as $line) {
                $lines[] = [
                    "{$row['key']}:{$line['code']}", $line['name'], $line['is_leaf'] ? 'account' : 'account_group',
                    ...array_map(fn (array $column): string => $line['values'][$column['key']] ?? '', $columns),
                ];
            }
        }

        return CsvExport::download(
            "report_{$key}_{$parameters['from']}_{$parameters['to']}.csv",
            ['row', 'label', 'type', ...array_column($columns, 'key')],
            $lines,
        );
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see MovementClassifier}
     */
    public static function classification(string $key, array $result): StreamedResponse
    {
        /** @var list<array{key: string, label: string, debit: string, credit: string, net: string}> $rows */
        $rows = $result['rows'];
        /** @var array{debit: string, credit: string, net: string} $total */
        $total = $result['total'];

        return CsvExport::download(
            "classification_{$key}".self::range($result).'.csv',
            ['row', 'label', 'debit', 'credit', 'net'],
            [
                ...array_map(fn (array $row): array => [$row['key'], $row['label'], $row['debit'], $row['credit'], $row['net']], $rows),
                ['total', '', $total['debit'], $total['credit'], $total['net']],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see DirectCashFlow}
     */
    public static function cashFlow(string $key, array $result): StreamedResponse
    {
        /** @var list<array{key: string, label: string, inflow: string, outflow: string, net: string}> $rows */
        $rows = $result['rows'];
        /** @var array{opening_balance: string, imported_opening_balance: string, net_increase: string, closing_balance: string} $cash */
        $cash = $result['cash'];

        return CsvExport::download(
            "cash-flow_{$key}".self::range($result).'.csv',
            ['row', 'label', 'inflow', 'outflow', 'net'],
            [
                ['opening_balance', '', '', '', $cash['opening_balance']],
                ['imported_opening_balance', '', '', '', $cash['imported_opening_balance']],
                ...array_map(fn (array $row): array => [$row['key'], $row['label'], $row['inflow'], $row['outflow'], $row['net']], $rows),
                ['net_increase', '', '', '', $cash['net_increase']],
                ['closing_balance', '', '', '', $cash['closing_balance']],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see EquityChanges}
     */
    public static function equityChanges(string $key, array $result): StreamedResponse
    {
        /** @var list<array{key: string, label: string, opening_balance: string, movement: string, closing_balance: string}> $components */
        $components = $result['components'];
        /** @var list<array{key: string, label: string, values: array<string, string>, total: string}> $rows */
        $rows = $result['rows'];

        return CsvExport::download(
            "equity-changes_{$key}".self::range($result).'.csv',
            ['row', 'label', ...array_column($components, 'key'), 'total'],
            [
                ['opening_balance', '', ...array_column($components, 'opening_balance'), ''],
                ...array_map(fn (array $row): array => [
                    $row['key'], $row['label'],
                    ...array_map(fn (array $component): string => $row['values'][$component['key']] ?? '', $components),
                    $row['total'],
                ], $rows),
                ['closing_balance', '', ...array_column($components, 'closing_balance'), ''],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function range(array $result): string
    {
        /** @var array{from: string, to: string} $parameters */
        $parameters = $result['parameters'];

        return "_{$parameters['from']}_{$parameters['to']}";
    }
}
