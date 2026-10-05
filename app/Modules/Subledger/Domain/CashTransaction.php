<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A receipt or payment on a cash account, in the currency of the account (SL-FR-03, SL-FR-11).
 * Only the cleared status changes after it is recorded.
 *
 * @property string $id
 * @property string $company_id
 * @property string $cash_account_id
 * @property CashDirection $direction
 * @property BigDecimal $amount
 * @property string $currency
 * @property Carbon $transaction_date
 * @property string|null $reference
 * @property string|null $document_id
 * @property string|null $journal_id the journal of a transfer between cash accounts, which has no document
 * @property Carbon|null $cleared_on
 * @property int|null $cleared_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CashTransaction extends CompanyScopedModel
{
    public const AMOUNT_SCALE = 6;

    protected $table = 'sl_cash_transactions';

    protected $fillable = ['cash_account_id', 'direction', 'amount', 'currency', 'transaction_date', 'reference', 'document_id', 'journal_id'];

    protected function casts(): array
    {
        return [
            'direction' => CashDirection::class,
            'amount' => DecimalCast::class.':'.self::AMOUNT_SCALE,
            'transaction_date' => 'date',
            'cleared_on' => 'date',
        ];
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function isCleared(): bool
    {
        return $this->cleared_on !== null;
    }
}
