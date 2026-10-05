<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * An amount of a debit side open item settled against a credit side one (SL-FR-05). Cancelled, never
 * deleted; the amounts go back to the open items.
 *
 * @property string $id
 * @property string $company_id
 * @property string $debit_item_id
 * @property string $credit_item_id
 * @property string|null $document_id the document whose posting made it
 * @property BigDecimal $amount in the currency of the open items
 * @property BigDecimal $functional_amount the part of the debit side open item, in the functional currency
 * @property BigDecimal $credit_functional_amount the part of the credit side open item
 * @property BigDecimal $realized_difference credit part less debit part; a gain when positive (SL-FR-06)
 * @property string|null $journal_id the journal that booked the difference of an allocation asked for afterwards
 * @property string|null $cancel_journal_id the journal that undid it when the allocation was cancelled
 * @property Carbon $allocation_date
 * @property string $status `active` or `cancelled`
 * @property int|null $created_by
 * @property Carbon|null $cancelled_on
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Allocation extends CompanyScopedModel
{
    protected $table = 'sl_allocations';

    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'debit_item_id', 'credit_item_id', 'document_id', 'amount', 'functional_amount', 'credit_functional_amount', 'realized_difference', 'journal_id', 'cancel_journal_id', 'allocation_date', 'status',
        'created_by', 'cancelled_on', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => DecimalCast::class.':6',
            'functional_amount' => DecimalCast::class.':6',
            'credit_functional_amount' => DecimalCast::class.':6',
            'realized_difference' => DecimalCast::class.':6',
            'allocation_date' => 'date',
            'cancelled_on' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
