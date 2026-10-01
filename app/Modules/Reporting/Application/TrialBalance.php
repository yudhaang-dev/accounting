<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\TrialBalanceRow;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Trial balance of a date range (RP-FR-05): opening balance, debit and credit movement and closing
 * balance per account, with totals and a visible `balanced` flag (RP-AC-01).
 */
final class TrialBalance
{
    private const COLUMNS = ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'];

    public function __construct(
        private readonly LedgerReader $ledger,
        private readonly CompanyDirectory $companies,
    ) {}

    /**
     * @return array{parameters: array{from: string, to: string, account_id: string|null}, functional_currency: string, balanced: bool, totals: array<string, string>, rows: list<array<string, string>>}
     */
    public function __invoke(string $companyId, string $from, string $to, ?string $accountId = null): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $rows = array_map($this->row(...), $this->ledger->trialBalance($companyId, $from, $to, $accountId));

        $totals = array_fill_keys(self::COLUMNS, BigDecimal::zero());

        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $totals[$column] = $totals[$column]->plus($row[$column]);
            }
        }

        $balanced = $totals['opening_debit']->isEqualTo($totals['opening_credit'])
            && $totals['debit']->isEqualTo($totals['credit'])
            && $totals['closing_debit']->isEqualTo($totals['closing_credit']);

        return [
            'parameters' => ['from' => $from, 'to' => $to, 'account_id' => $accountId],
            'functional_currency' => $company->functionalCurrency,
            'balanced' => $balanced,
            'totals' => array_map($this->format(...), $totals),
            'rows' => array_map(fn (array $row): array => array_map(
                fn (string|BigDecimal $value): string => $value instanceof BigDecimal ? $this->format($value) : $value,
                $row,
            ), $rows),
        ];
    }

    /**
     * @return array{account_id: string, code: string, name: string, class: string, opening_debit: BigDecimal, opening_credit: BigDecimal, debit: BigDecimal, credit: BigDecimal, closing_debit: BigDecimal, closing_credit: BigDecimal}
     */
    private function row(TrialBalanceRow $row): array
    {
        return [
            'account_id' => $row->accountId,
            'code' => $row->code,
            'name' => $row->name,
            'class' => $row->class->value,
            'opening_debit' => BigDecimal::of($row->openingDebit),
            'opening_credit' => BigDecimal::of($row->openingCredit),
            'debit' => BigDecimal::of($row->debit),
            'credit' => BigDecimal::of($row->credit),
            'closing_debit' => BigDecimal::of($row->closingDebit),
            'closing_credit' => BigDecimal::of($row->closingCredit),
        ];
    }

    private function format(BigDecimal $value): string
    {
        return (string) $value->toScale(max(0, (int) config('ledger.default_scale')), RoundingMode::HalfUp);
    }
}
