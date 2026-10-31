<?php

namespace App\Modules\Subledger\Domain;

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;
use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What is left to settle of a document (SL-FR-04), in the transaction currency and in the functional one.
 * `side` is the side of the control account line the document posted, so the open items of a control
 * account add up to its balance. Only the remaining amounts and the status change after it is created.
 *
 * @property string $id
 * @property string $company_id
 * @property string $document_id
 * @property string $party_id
 * @property ControlRole $control_role
 * @property string|null $posting_profile_id the profile the route named; empty when the party's profile applies (SL-FR-02)
 * @property DocumentKind $kind
 * @property string $side `debit` or `credit`
 * @property string $document_number
 * @property Carbon $document_date
 * @property Carbon $due_date
 * @property string $currency
 * @property BigDecimal $original_amount
 * @property BigDecimal $remaining_amount
 * @property BigDecimal $original_functional
 * @property BigDecimal $remaining_functional
 * @property BigDecimal $exchange_rate functional currency per 1 unit of `currency`, as recorded
 * @property OpenItemStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OpenItem extends CompanyScopedModel
{
    protected $table = 'sl_open_items';

    protected $attributes = ['status' => 'open'];

    protected $fillable = [
        'document_id', 'party_id', 'control_role', 'posting_profile_id', 'kind', 'side', 'document_number', 'document_date', 'due_date', 'currency',
        'original_amount', 'remaining_amount', 'original_functional', 'remaining_functional', 'exchange_rate', 'status',
    ];

    protected function casts(): array
    {
        return [
            'control_role' => ControlRole::class,
            'kind' => DocumentKind::class,
            'document_date' => 'date',
            'due_date' => 'date',
            'original_amount' => DecimalCast::class.':6',
            'remaining_amount' => DecimalCast::class.':6',
            'original_functional' => DecimalCast::class.':6',
            'remaining_functional' => DecimalCast::class.':6',
            'exchange_rate' => DecimalCast::class.':12',
            'status' => OpenItemStatus::class,
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
