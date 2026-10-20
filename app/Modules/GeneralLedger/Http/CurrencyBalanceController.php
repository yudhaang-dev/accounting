<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Contracts\CurrencyBalanceRow;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;

class CurrencyBalanceController
{
    public function __construct(
        private readonly LedgerReader $ledger,
        private readonly DimensionSets $dimensionSets,
        private readonly DataScopeContext $scope,
    ) {}

    /**
     * Balance of each account in each transaction currency up to and including `as_of` (GL-FR-08),
     * ordered by account code and currency. Every row has `debit` and `credit` in that currency and
     * `functional_debit` and `functional_credit`, the same movements as recorded in the functional
     * currency; combinations without movement are left out. `account_id` limits it to one account
     * (404 when it is not an account of the company). For a user limited to some dimension values only
     * the lines inside that scope are added up and `restricted` is true (IA-FR-03).
     *
     * @response array{data: array{as_of: string, restricted: bool, balances: list<array{account_id: string, code: string, name: string, currency: string, debit: string, credit: string, functional_debit: string, functional_credit: string}>}}
     */
    public function index(CurrencyBalancesRequest $request, string $companyId): JsonResponse
    {
        /** @var array{as_of: string, account_id?: string|null} $params */
        $params = $request->validated();
        $accountId = $params['account_id'] ?? null;

        if ($accountId !== null) {
            Account::query()->findOrFail($accountId);
        }

        $scope = $this->scope->current();
        $setIds = $scope->isRestricted() ? $this->dimensionSets->setIdsWithin($companyId, $scope->allowed()) : null;

        return new JsonResponse(['data' => [
            'as_of' => $params['as_of'],
            'restricted' => $scope->isRestricted(),
            'balances' => array_map(fn (CurrencyBalanceRow $row): array => [
                'account_id' => $row->accountId,
                'code' => $row->code,
                'name' => $row->name,
                'currency' => $row->currency,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'functional_debit' => $row->functionalDebit,
                'functional_credit' => $row->functionalCredit,
            ], $this->ledger->currencyBalances($companyId, $params['as_of'], $accountId, $setIds)),
        ]]);
    }
}
