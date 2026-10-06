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
 * General ledger of one account (RP-FR-06): the journal lines of a date range, oldest first, each
 * with the amount in the transaction currency, the link to its journal, entry and source reference,
 * and the running balance, which starts from the opening balance of the ledger before the range.
 * Balances are debit minus credit, so a negative balance is a credit balance.
 */
final class GeneralLedgerReport
{
    public function __construct(
        private readonly ReportLedger $ledger,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
        private readonly HistoryCoverage $coverage,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $companyId, string $accountId, string $from, string $to, ?DataScope $scope = null, ?ReportFilter $filter = null): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $account = $this->accounts->find($companyId, $accountId) ?? throw new ApiException(ErrorCode::NotFound);
        $scope ??= DataScope::unrestricted();
        $filter ??= new ReportFilter(accountId: $accountId);
        $slice = $this->ledger->slice($companyId, $filter, $scope);
        $scale = $this->currencies->functionalScale($companyId);

        $opening = BigDecimal::zero();

        foreach ($slice->trialBalance($from, $to) as $row) {
            $opening = BigDecimal::of($row->openingDebit)->minus($row->openingCredit);
        }

        $balance = $opening;
        $debit = $credit = BigDecimal::zero();
        $lines = [];

        foreach ($slice->accountLines($from, $to, $accountId) as $line) {
            $balance = $balance->plus($line->debit)->minus($line->credit);
            $debit = $debit->plus($line->debit);
            $credit = $credit->plus($line->credit);

            $lines[] = [
                'journal_id' => $line->journalId,
                'journal_number' => $line->journalNumber,
                'entry_id' => $line->entryId,
                'accounting_date' => $line->accountingDate,
                'source' => $line->source->value,
                'transaction_type_key' => $line->transactionTypeKey,
                'source_references' => $line->sourceReferences,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'currency' => $line->currency,
                'transaction_amount' => $line->transactionAmount,
                'exchange_rate' => $line->exchangeRate,
                'balance' => $this->format($balance, $scale),
            ];
        }

        return [
            'parameters' => ['from' => $from, 'to' => $to, ...$filter->toArray()],
            'functional_currency' => $company->functionalCurrency,
            'restricted' => $scope->isRestricted(),
            ...$this->coverage->assess($companyId, $from, $to),
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'opening_balance' => $this->format($opening, $scale),
            'lines' => $lines,
            'totals' => ['debit' => $this->format($debit, $scale), 'credit' => $this->format($credit, $scale)],
            'closing_balance' => $this->format($balance, $scale),
        ];
    }

    /** @param  int<0, max>  $scale */
    private function format(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
