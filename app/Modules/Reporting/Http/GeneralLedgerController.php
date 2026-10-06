<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\GeneralLedgerReport;
use App\Modules\Reporting\Application\ReportFilter;
use App\Platform\Export\CsvExport;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GeneralLedgerController
{
    /**
     * General ledger of the account `account_id` for `from`..`to` (RP-FR-06): `opening_balance`, the
     * `lines` oldest first with the running `balance`, the amount in the transaction currency, and
     * the `journal_id`, `entry_id` and `source_references` to follow, then `totals` and
     * `closing_balance`. Balances are debit minus credit, so a credit balance is negative. The
     * filters of the trial balance apply (`dimensions`, `transaction_type_keys`, `source`); for a user
     * limited to some dimension values only the lines inside that scope count (IA-FR-03). With
     * `Accept: text/csv` the same report is downloaded, under the same permission (X-FR-02).
     *
     * @response array{data: array{parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, account: array{id: string, code: string, name: string}, opening_balance: string, lines: list<array{journal_id: string, journal_number: string, entry_id: string|null, accounting_date: string, source: string, transaction_type_key: string|null, source_references: array<string, mixed>|null, description: string|null, debit: string, credit: string, currency: string, transaction_amount: string, exchange_rate: string, balance: string}>, totals: array{debit: string, credit: string}, closing_balance: string}}
     */
    public function show(GeneralLedgerRequest $request, GeneralLedgerReport $report, DataScopeContext $scope, string $companyId): JsonResponse|Response
    {
        /** @var array<string, mixed>&array{from: string, to: string, account_id: string} $params */
        $params = $request->validated();

        $result = $report($companyId, $params['account_id'], $params['from'], $params['to'], $scope->current(), ReportFilter::fromParameters($params));

        if (! str_contains((string) $request->header('Accept'), 'text/csv')) {
            return new JsonResponse(['data' => $result]);
        }

        $rows = array_map(fn (array $line): array => [
            $line['accounting_date'], $line['journal_number'], $line['entry_id'] ?? '', $line['transaction_type_key'] ?? '', $line['description'] ?? '',
            $line['debit'], $line['credit'], $line['currency'], $line['transaction_amount'], $line['exchange_rate'], $line['balance'],
        ], $result['lines']);

        array_unshift($rows, [$params['from'], '', '', '', 'Opening balance', '', '', '', '', '', $result['opening_balance']]);

        return CsvExport::download(
            "general-ledger_{$result['account']['code']}_{$params['from']}_{$params['to']}.csv",
            ['accounting_date', 'journal_number', 'entry_id', 'transaction_type_key', 'description', 'debit', 'credit', 'currency', 'transaction_amount', 'exchange_rate', 'balance'],
            $rows,
        );
    }
}
