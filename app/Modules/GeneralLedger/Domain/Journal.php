<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Platform\Retention\RetainedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A posted journal (GL-FR-05). Append-only: the database rejects UPDATE and DELETE (GL-FR-06, P-03),
 * and this model fails earlier with a clearer message. Corrections are reversals and replacements.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property Carbon $accounting_date
 * @property string $period_id
 * @property Carbon|null $document_date
 * @property string $description
 * @property string $source
 * @property string|null $transaction_type_key
 * @property int|null $transaction_type_version
 * @property array<string, mixed>|null $source_references
 * @property string|null $reverses_journal_id
 * @property string|null $entry_id
 * @property int|null $posted_by
 * @property Carbon $posted_at
 */
class Journal extends RetainedCompanyModel
{
    public const UPDATED_AT = null;

    protected $table = 'gl_journals';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journals are immutable; post a reversal instead (GL-FR-06).'));
    }

    protected function casts(): array
    {
        return [
            'accounting_date' => 'date',
            'document_date' => 'date',
            'transaction_type_version' => 'integer',
            'source_references' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
    }
}
