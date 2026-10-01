<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Support\Company\BelongsToCompany;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/**
 * Aggregated balance per account, period, dimension set and transaction currency, kept up to date
 * by posting (GL-FR-05). Read model only: rows are written by `AccountBalances` in one upsert per key.
 *
 * @property string $company_id
 * @property string $account_id
 * @property string $period_id
 * @property string $dimension_set_id
 * @property string $currency
 * @property BigDecimal $debit
 * @property BigDecimal $credit
 * @property BigDecimal $transaction_debit
 * @property BigDecimal $transaction_credit
 */
class AccountBalance extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'gl_account_balances';

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => DecimalCast::class.':6',
            'credit' => DecimalCast::class.':6',
            'transaction_debit' => DecimalCast::class.':6',
            'transaction_credit' => DecimalCast::class.':6',
        ];
    }
}
