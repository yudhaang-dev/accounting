<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\TrialBalance;
use App\Platform\Export\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrialBalanceController
{
    /**
     * Trial balance for `from`..`to`: opening balance (the ledger before `from`), movement and
     * closing balance per account, totals and `balanced`. `account_id` narrows it to one account.
     * Drill down to the journals with `journals?filter[account_id]=&filter[from]=&filter[to]=`.
     * With `Accept: text/csv` the same report is downloaded, under the same permission (X-FR-02).
     *
     * @response array{data: array{parameters: array{from: string, to: string, account_id: string|null}, functional_currency: string, balanced: bool, totals: array{opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string}, rows: list<array{account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string}>}}
     */
    public function show(TrialBalanceRequest $request, TrialBalance $report, string $companyId): JsonResponse|Response
    {
        /** @var array{from: string, to: string, account_id?: string|null} $params */
        $params = $request->validated();

        $result = $report($companyId, $params['from'], $params['to'], $params['account_id'] ?? null);

        if (! $this->wantsCsv($request)) {
            return new JsonResponse(['data' => $result]);
        }

        $rows = array_map(fn (array $row): array => [
            $row['code'], $row['name'], $row['class'],
            $row['opening_debit'], $row['opening_credit'], $row['debit'], $row['credit'], $row['closing_debit'], $row['closing_credit'],
        ], $result['rows']);

        $rows[] = ['', 'Total', '', ...array_values($result['totals'])];

        return CsvExport::download(
            "trial-balance_{$params['from']}_{$params['to']}.csv",
            ['code', 'name', 'class', 'opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'],
            $rows,
        );
    }

    private function wantsCsv(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'text/csv');
    }
}
