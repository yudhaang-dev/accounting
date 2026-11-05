<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\ReportFilter;
use App\Modules\Reporting\Application\TrialBalance;
use App\Platform\Export\CsvExport;
use App\Platform\Export\ExportFormat;
use App\Platform\Export\TableExport;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TrialBalanceController
{
    /**
     * Trial balance for `from`..`to`: opening balance (the ledger before `from`), movement and
     * closing balance per account, totals and `balanced`. `account_id` narrows it to one account and `dimensions[<type key>]=<value id>` to the lines in that
     * value or any value below it (roll-up). `transaction_type_keys[]` keeps the lines of journals with
     * those keys and `source` (`entry`, `subledger` or `system`) those from that source; the opening
     * balance is then the filtered ledger before `from`. Lines without dimensions stay in unless a
     * dimension filter is given.
     * Drill down to the journals with `journals?filter[account_id]=&filter[from]=&filter[to]=`.
     * `conversion_batch` names the opening balance batch the ledger started from (cutover date, ledger
     * start date, history granularity), or is null. With `adjustment_keys[]` the result also has a
     * `workpaper` (RP-AC-11): per account the closing balance `before_*` the journals with those keys,
     * the `adjustment_*` they add as a net debit or credit, and the balance `after_*` them, which is the
     * closing balance, so before + adjustment = after for every account. The download is always the plain
     * trial balance.
     * With `Accept: text/csv`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (Excel) or
     * `application/pdf` the same report is downloaded, under the same permission (X-FR-02); Excel and PDF are
     * refused with 422 `EXPORT_TOO_LARGE` beyond the row limit, CSV is not.
     * For a user limited to some dimension values only the lines inside that scope are added up and
     * `restricted` is true (IA-FR-03); the download is limited the same way.
     *
     * @response array{data: array{parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, balanced: bool, conversion_batch: array{id: string, source_key: string, source_system: string|null, cutover_date: string, ledger_start_date: string, history_granularity: string|null, journal_id: string, journal_number: string}|null, workpaper?: array{adjustment_keys: list<string>, balanced: bool, totals: array<string, string>, rows: list<array<string, string>>}, totals: array{opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string}, rows: list<array{account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string}>}}
     */
    public function show(TrialBalanceRequest $request, TrialBalance $report, DataScopeContext $scope, string $companyId): JsonResponse|Response
    {
        /** @var array<string, mixed>&array{from: string, to: string, adjustment_keys?: list<string>} $params */
        $params = $request->validated();

        $result = $report($companyId, $params['from'], $params['to'], ReportFilter::fromParameters($params), $scope->current(), array_values(array_unique($params['adjustment_keys'] ?? [])));

        $format = ExportFormat::wanted($request);

        if ($format === null) {
            return new JsonResponse(['data' => $result]);
        }

        if ($format !== ExportFormat::Csv) {
            return TableExport::download(ReportTables::trialBalance($companyId, $result), $format, "trial-balance_{$params['from']}_{$params['to']}");
        }

        $rows = array_map(fn (array $row): array => [
            $row['code'], $row['name'], $row['class'],
            $row['opening_debit'], $row['opening_credit'], $row['debit'], $row['credit'], $row['closing_debit'], $row['closing_credit'],
        ], $result['rows']);

        $rows[] = ['', $result['restricted'] ? 'Total (restricted)' : 'Total', '', ...array_values($result['totals'])];

        return CsvExport::download(
            "trial-balance_{$params['from']}_{$params['to']}.csv",
            ['code', 'name', 'class', 'opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'],
            $rows,
        );
    }
}
