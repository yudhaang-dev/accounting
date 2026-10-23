<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Application\RunStatements;
use App\Platform\Export\CsvExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV download of the group statements of a run (X-FR-02, CO-FR-10): one row per account of the balance sheet and
 * the income statement, their totals, one per intragroup pair and one per entity of the reconciliation.
 */
final class StatementsCsv
{
    /**
     * @param  array<string, mixed>  $statements  result of {@see RunStatements}
     */
    public static function download(array $statements): StreamedResponse
    {
        $rows = [];

        /** @var array<string, mixed> $sheet */
        $sheet = $statements['balance_sheet'];

        foreach (['assets', 'liabilities', 'equity'] as $section) {
            foreach ($sheet[$section]['lines'] as $line) {
                $rows[] = ['balance_sheet', $section, $line['code'], $line['name'], $line['amount']];
            }

            $rows[] = ['balance_sheet', $section, '', 'total', $sheet[$section]['total']];
        }

        $rows[] = ['balance_sheet', 'equity', '', 'current_year_earnings', $sheet['current_year_earnings']];
        $rows[] = ['balance_sheet', 'check', '', 'difference', $sheet['difference']];

        /** @var array<string, mixed> $income */
        $income = $statements['income_statement'];

        foreach (['income', 'expenses'] as $section) {
            foreach ($income[$section]['lines'] as $line) {
                $rows[] = ['income_statement', $section, $line['code'], $line['name'], $line['amount']];
            }

            $rows[] = ['income_statement', $section, '', 'total', $income[$section]['total']];
        }

        $rows[] = ['income_statement', 'result', '', 'net_result', $income['net_result']];

        /** @var array<string, mixed> $eliminations */
        $eliminations = $statements['eliminations'];

        foreach ($eliminations['pairs'] as $pair) {
            $rows[] = ['eliminations', $pair['kind'].':'.$pair['status'], $pair['company_a_id'], $pair['company_b_id'], $pair['difference']];
        }

        foreach ($eliminations['adjustments'] as $adjustment) {
            $rows[] = ['adjustments', $adjustment['status'], $adjustment['source'], $adjustment['reason'], ''];
        }

        /** @var array<string, mixed> $reconciliation */
        $reconciliation = $statements['reconciliation'];

        foreach ($reconciliation['entities'] as $entity) {
            $rows[] = ['reconciliation', 'entity', $entity['company_id'], $entity['name'], $entity['contribution']];
        }

        $rows[] = ['reconciliation', 'total', '', 'total', $reconciliation['total']];

        return CsvExport::download(
            "statements_{$statements['label']}_{$statements['from']}_{$statements['to']}.csv",
            ['statement', 'section', 'code', 'name', 'amount'],
            $rows,
        );
    }
}
