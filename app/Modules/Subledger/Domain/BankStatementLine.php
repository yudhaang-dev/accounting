<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * A line of a bank statement, in the currency of the cash account. Only its match changes after the
 * import (SL-FR-13).
 *
 * @property string $id
 * @property string $company_id
 * @property string $statement_id
 * @property string $cash_account_id
 * @property int $line_number the line of the CSV file, counting the header
 * @property Carbon $transaction_date
 * @property string|null $description
 * @property string|null $reference
 * @property CashDirection $direction
 * @property BigDecimal $amount
 * @property string|null $cash_transaction_id
 * @property Carbon|null $matched_at
 * @property int|null $matched_by
 * @property bool $cleared_by_match
 */
class BankStatementLine extends CompanyScopedModel
{
    protected $table = 'sl_bank_statement_lines';

    protected $fillable = ['statement_id', 'cash_account_id', 'line_number', 'transaction_date', 'description', 'reference', 'direction', 'amount'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'direction' => CashDirection::class,
            'amount' => DecimalCast::class.':'.CashTransaction::AMOUNT_SCALE,
            'matched_at' => 'datetime',
            'cleared_by_match' => 'boolean',
        ];
    }

    public function isMatched(): bool
    {
        return $this->cash_transaction_id !== null;
    }
}
