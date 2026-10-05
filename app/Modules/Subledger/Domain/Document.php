<?php

namespace App\Modules\Subledger\Domain;

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;
use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * What an entry with a subledger route recorded about a party (SL-FR-04). One document per journal;
 * it never changes, and the database refuses an update.
 *
 * @property string $id
 * @property string $company_id
 * @property DocumentKind $kind
 * @property ControlRole $control_role
 * @property string $party_id
 * @property string|null $cash_account_id
 * @property string $journal_id
 * @property string|null $entry_id
 * @property string $document_number
 * @property Carbon $document_date
 * @property Carbon $due_date
 * @property Carbon $accounting_date
 * @property string $currency
 * @property BigDecimal $amount in the transaction currency
 * @property BigDecimal $functional_amount
 * @property BigDecimal $exchange_rate functional currency per 1 unit of `currency`
 * @property string $description
 * @property bool $is_opening an open item of the conversion date, recorded with the opening balance journal (SL-FR-10)
 * @property string|null $control_account_id the control account of an opening document, whose journal holds several
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Document extends CompanyScopedModel
{
    protected $table = 'sl_documents';

    protected $fillable = [
        'kind', 'control_role', 'party_id', 'cash_account_id', 'journal_id', 'entry_id', 'document_number', 'document_date', 'due_date',
        'accounting_date', 'currency', 'amount', 'functional_amount', 'exchange_rate', 'description', 'created_by',
        'is_opening', 'control_account_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'control_role' => ControlRole::class,
            'document_date' => 'date',
            'due_date' => 'date',
            'accounting_date' => 'date',
            'is_opening' => 'boolean',
            'amount' => DecimalCast::class.':6',
            'functional_amount' => DecimalCast::class.':6',
            'exchange_rate' => DecimalCast::class.':12',
        ];
    }

    /** @return HasOne<DocumentReversal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(DocumentReversal::class);
    }

    /** @return HasOne<OpenItem, $this> */
    public function openItem(): HasOne
    {
        return $this->hasOne(OpenItem::class);
    }
}
