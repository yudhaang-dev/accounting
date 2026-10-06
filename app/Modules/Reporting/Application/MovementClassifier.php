<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Sorts the movement of a date range into the rows of a classification (RP-FR-03). Each account and
 * transaction type key combination goes to the first rule that matches it; what no rule matches goes
 * to the `unclassified` row, so the rows always add up to the movement of the ledger and nothing
 * is dropped. The cash flow and equity reports read their rows from here.
 */
final class MovementClassifier
{
    public function __construct(
        private readonly ReportLedger $ledger,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
    ) {}

    /**
     * With `$cashAccountIds` the lines are the counter lines of the journals that touch those accounts
     * instead of all movement (RP-FR-09): a payment is classified by what it paid for.
     *
     * @param  list<string>|null  $cashAccountIds
     * @param  array{rows: list<array{key: string, label: string}>, rules: list<array{row: string, accounts: list<array<string, string>>, transaction_type_keys: list<string>}>}  $structure
     * @return array{parameters: array<string, mixed>, functional_currency: string, restricted: bool, rows: list<array{key: string, label: string, debit: string, credit: string, net: string}>, total: array{debit: string, credit: string, net: string}, unclassified: list<array{account_id: string, code: string, name: string, transaction_type_key: string|null}>}
     */
    public function __invoke(string $companyId, array $structure, string $from, string $to, ?DataScope $scope = null, ?ReportFilter $filter = null, ?array $cashAccountIds = null): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter;

        $chart = $this->accounts->all($companyId);
        $matchers = [];

        foreach ($structure['rules'] as $rule) {
            $matchers[] = ['row' => $rule['row'], 'accounts' => array_flip(ReportStructure::resolve($rule['accounts'], $chart)), 'keys' => $rule['transaction_type_keys']];
        }

        $totals = [];
        $unclassified = [];

        $slice = $this->ledger->slice($companyId, $filter, $scope);
        $lines = $cashAccountIds === null ? $slice->movementByTransactionType($from, $to) : $slice->cashCounterMovement($from, $to, $cashAccountIds);

        foreach ($lines as $line) {
            $row = ClassificationStructure::UNCLASSIFIED;

            foreach ($matchers as $matcher) {
                if (isset($matcher['accounts'][$line->accountId]) && ($matcher['keys'] === [] || in_array($line->transactionTypeKey, $matcher['keys'], true))) {
                    $row = $matcher['row'];

                    break;
                }
            }

            $totals[$row]['debit'] = ($totals[$row]['debit'] ?? BigDecimal::zero())->plus($line->debit);
            $totals[$row]['credit'] = ($totals[$row]['credit'] ?? BigDecimal::zero())->plus($line->credit);

            if ($row === ClassificationStructure::UNCLASSIFIED) {
                $unclassified[] = ['account_id' => $line->accountId, 'transaction_type_key' => $line->transactionTypeKey];
            }
        }

        $scale = $this->currencies->functionalScale($companyId);
        $labelled = $structure['rows'];

        if (isset($totals[ClassificationStructure::UNCLASSIFIED])) {
            $labelled[] = ['key' => ClassificationStructure::UNCLASSIFIED, 'label' => (string) __('reports.unclassified_row')];
        }

        $rows = [];
        $sum = ['debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero()];

        foreach ($labelled as $row) {
            $debit = $totals[$row['key']]['debit'] ?? BigDecimal::zero();
            $credit = $totals[$row['key']]['credit'] ?? BigDecimal::zero();
            $sum = ['debit' => $sum['debit']->plus($debit), 'credit' => $sum['credit']->plus($credit)];
            $rows[] = ['key' => $row['key'], 'label' => $row['label'], ...$this->amounts($debit, $credit, $scale)];
        }

        $byId = [];

        foreach ($chart as $account) {
            $byId[$account->id] = $account;
        }

        return [
            'parameters' => ['from' => $from, 'to' => $to, ...$filter->toArray()],
            'functional_currency' => $company->functionalCurrency,
            'restricted' => $scope->isRestricted(),
            'rows' => $rows,
            'total' => $this->amounts($sum['debit'], $sum['credit'], $scale),
            'unclassified' => array_map(fn (array $line): array => ['account_id' => $line['account_id'], 'code' => $byId[$line['account_id']]->code ?? '', 'name' => $byId[$line['account_id']]->name ?? '', 'transaction_type_key' => $line['transaction_type_key']], $unclassified),
        ];
    }

    /**
     * @param  int<0, max>  $scale
     * @return array{debit: string, credit: string, net: string}
     */
    private function amounts(BigDecimal $debit, BigDecimal $credit, int $scale): array
    {
        return [
            'debit' => (string) $debit->toScale($scale, RoundingMode::HalfUp),
            'credit' => (string) $credit->toScale($scale, RoundingMode::HalfUp),
            'net' => (string) $debit->minus($credit)->toScale($scale, RoundingMode::HalfUp),
        ];
    }
}
