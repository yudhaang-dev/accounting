<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import of a bank statement into a cash account, with the balance the bank reports on its closing
 * date (SL-FR-13).
 *
 * @property string $id
 * @property string $company_id
 * @property string $cash_account_id
 * @property string|null $file_name
 * @property string $file_hash
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property Carbon $closing_date
 * @property BigDecimal $closing_balance
 * @property int $line_count
 * @property int|null $imported_by
 * @property Carbon|null $created_at
 */
class BankStatement extends CompanyScopedModel
{
    protected $table = 'sl_bank_statements';

    protected $fillable = ['cash_account_id', 'file_name', 'file_hash', 'period_from', 'period_to', 'closing_date', 'closing_balance', 'line_count', 'imported_by'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'closing_date' => 'date',
            'closing_balance' => DecimalCast::class.':'.CashTransaction::AMOUNT_SCALE,
        ];
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'statement_id');
    }
}
