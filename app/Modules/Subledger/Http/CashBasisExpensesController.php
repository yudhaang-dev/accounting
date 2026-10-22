<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\CashBasisExpenses;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Export\CsvExport;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CashBasisExpensesController
{
    /**
     * Cash paid to suppliers from `from` to `to`, attributed to the expense accounts of the bills it settled (RP-FR-11):
     * each allocation of a payment to a payables bill dated in the range is spread over the lines of the bill's
     * journal in proportion to their amounts. `accounts` are the expense accounts per dimension set; `other_accounts`
     * is what went to lines that are not expenses (input tax, prepaid assets) and `unattributed` what settled a bill
     * with no lines to follow, such as an open item of the conversion date, so `total` is the cash paid on bills.
     * Advances count when they are applied. This is the cash counterpart of the accrual figures of the plan
     * comparison; payments that settle no bill and expenses paid straight from a cash account are in the direct cash
     * flow instead. A user limited to some dimension values is refused, since a bill is not split by dimension value.
     * With `Accept: text/csv` the same report is downloaded, under the same permission (X-FR-02).
     *
     * @response array{data: array{parameters: array{from: string, to: string}, functional_currency: string, accounts: list<array{account_id: string, account_code: string, account_name: string, dimension_set_id: string, cash_paid: string}>, other_accounts: string, unattributed: string, total: string}}
     *
     * @throws ApiException FORBIDDEN for a user with a data scope
     */
    public function show(CashBasisExpensesRequest $request, CashBasisExpenses $report, DataScopeContext $scope, string $companyId): JsonResponse|Response
    {
        if ($scope->current()->isRestricted()) {
            throw new ApiException(ErrorCode::Forbidden, (string) __('documents.cash_basis_scope_restricted'));
        }

        $result = $report($companyId, (string) $request->validated('from'), (string) $request->validated('to'));

        if (! str_contains((string) $request->header('Accept'), 'text/csv')) {
            return new JsonResponse(['data' => $result]);
        }

        $rows = array_map(fn (array $row): array => [$row['account_code'], $row['account_name'], $row['dimension_set_id'], $row['cash_paid']], $result['accounts']);
        $rows[] = ['', 'Other accounts', '', $result['other_accounts']];
        $rows[] = ['', 'Unattributed', '', $result['unattributed']];
        $rows[] = ['', 'Total', '', $result['total']];

        return CsvExport::download("cash_basis_expenses_{$result['parameters']['from']}_{$result['parameters']['to']}.csv", ['account_code', 'account_name', 'dimension_set_id', 'cash_paid'], $rows);
    }
}
