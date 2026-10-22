<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Application\RunResult;
use App\Platform\Export\CsvExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV download of a run result (X-FR-02, CO-FR-10): the same numbers as the JSON, one row per entity account
 * behind a group line, a row for the line itself, one for each exception, and the grand total. Every group
 * line stays traceable to the accounts and entities it adds up.
 */
final class RunCsv
{
    private const COLUMNS = ['type', 'group_account_code', 'group_account_name', 'group_account_class', 'company_id', 'company_name', 'account_code', 'account_name', 'opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'];

    public static function wanted(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'text/csv');
    }

    /**
     * @param  array<string, mixed>  $result  result of {@see RunResult}
     */
    public static function download(array $result): StreamedResponse
    {
        /** @var list<array{company_id: string, name: string}> $entities */
        $entities = $result['entities'];
        $names = array_column($entities, 'name', 'company_id');
        /** @var list<array<string, mixed>> $lines */
        $lines = $result['lines'];
        /** @var list<array<string, string>> $exceptions */
        $exceptions = $result['exceptions'];
        /** @var array{all: array<string, string>} $totals */
        $totals = $result['totals'];

        $rows = [];

        foreach ($lines as $line) {
            foreach ($line['sources'] as $source) {
                $rows[] = self::row('source', $line, $source, $names);
            }

            $rows[] = self::row('line', $line, $line, []);
        }

        foreach ($exceptions as $exception) {
            $rows[] = self::row('exception', [], $exception, $names);
        }

        $rows[] = self::row('total', [], $totals['all'], []);

        return CsvExport::download(
            "{$result['label']}_{$result['from']}_{$result['to']}.csv",
            self::COLUMNS,
            $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $line  the group line, or empty
     * @param  array<string, mixed>  $figures  the source, the line or the totals
     * @param  array<string, string>  $names  company names by ID
     * @return list<string>
     */
    private static function row(string $type, array $line, array $figures, array $names): array
    {
        $company = (string) ($figures['company_id'] ?? '');

        return [
            $type,
            (string) ($line['code'] ?? ''),
            (string) ($line['name'] ?? ''),
            (string) ($line['class'] ?? ''),
            $company,
            $names[$company] ?? '',
            $type === 'line' || $type === 'total' ? '' : (string) ($figures['code'] ?? ''),
            $type === 'line' || $type === 'total' ? '' : (string) ($figures['name'] ?? ''),
            (string) ($figures['opening_debit'] ?? ''),
            (string) ($figures['opening_credit'] ?? ''),
            (string) ($figures['debit'] ?? ''),
            (string) ($figures['credit'] ?? ''),
            (string) ($figures['closing_debit'] ?? ''),
            (string) ($figures['closing_credit'] ?? ''),
        ];
    }
}
