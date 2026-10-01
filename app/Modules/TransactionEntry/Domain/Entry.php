<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An entry that becomes a journal once approved and posted (TE-FR-07). Only a draft can be edited;
 * a posted entry keeps the ID and number of its journal (TE-FR-10).
 *
 * @property string $id
 * @property string $company_id
 * @property string $transaction_type_id
 * @property string $transaction_type_key
 * @property int $transaction_type_version
 * @property string $number
 * @property EntryStatus $status
 * @property string $source
 * @property Carbon $accounting_date
 * @property Carbon|null $document_date
 * @property string $description
 * @property list<array{account_id: string, debit: string, credit: string, description: string|null}> $lines
 * @property int $created_by
 * @property string|null $journal_id
 * @property string|null $journal_number
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Entry extends VersionedCompanyModel
{
    protected $table = 'te_entries';

    protected $attributes = ['status' => 'draft', 'source' => 'manual'];

    protected $fillable = [
        'transaction_type_id', 'transaction_type_key', 'transaction_type_version', 'number', 'status', 'source',
        'accounting_date', 'document_date', 'description', 'lines', 'created_by', 'journal_id', 'journal_number', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntryStatus::class,
            'accounting_date' => 'date',
            'document_date' => 'date',
            'lines' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    /** @return HasMany<EntryDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(EntryDecision::class, 'entry_id')->orderBy('created_at')->orderBy('id');
    }
}
