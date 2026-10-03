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
 * @property list<array{account_id: string, debit: string, credit: string, description: string|null, dimensions?: array<string, string>, currency?: string|null, exchange_rate?: string|null, applied_rate?: string|null, rate_type_key?: string|null}> $lines
 * @property array<string, mixed>|null $field_values values of the fields of a `structured` version; null for `free_lines`
 * @property string|null $currency currency of a structured entry; null for the functional currency
 * @property string|null $exchange_rate the rate a structured entry brought, if it did
 * @property array{policy_version: int, rule: int|null, amount: string, stages: list<string>, auto_post: bool, separation_of_duties: bool, approvals: list<int>}|null $approval the approval plan given when the entry was submitted, and who has approved so far
 * @property string|null $reverses_journal_id the journal a `system.reversal` entry reverses
 * @property string|null $reason why a reversal was asked for
 * @property string|null $replaces_entry_id the posted entry this one replaces
 * @property Carbon|null $auto_reverse_on the date the system reverses the entry once it is posted
 * @property string|null $auto_reversal_status `pending`, `done` or `failed`, once the entry is posted with `auto_reverse_on`
 * @property string|null $auto_reversal_error why the scheduled reversal failed
 * @property string|null $auto_reversal_entry_id the reversal entry the system made
 * @property string|null $integration_client_id the client that sent the financial event this entry came from
 * @property string|null $source_system
 * @property string|null $source_transaction_id
 * @property string|null $source_document_number
 * @property array<string, mixed>|null $source_references metadata of the event, as sent
 * @property int|null $created_by null for an entry that came from a financial event
 * @property string|null $journal_id
 * @property string|null $journal_number
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Entry extends VersionedCompanyModel
{
    protected $table = 'te_entries';

    protected static function booted(): void
    {
        static::addGlobalScope(new WithinDataScope);
    }

    protected $attributes = ['status' => 'draft', 'source' => 'manual'];

    protected $fillable = [
        'transaction_type_id', 'transaction_type_key', 'transaction_type_version', 'number', 'status', 'source',
        'accounting_date', 'document_date', 'description', 'lines', 'field_values', 'currency', 'exchange_rate', 'approval', 'reverses_journal_id', 'reason', 'replaces_entry_id', 'auto_reverse_on', 'auto_reversal_status', 'auto_reversal_error', 'auto_reversal_entry_id', 'integration_client_id', 'source_system', 'source_transaction_id', 'source_document_number', 'source_references', 'created_by', 'journal_id', 'journal_number', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntryStatus::class,
            'accounting_date' => 'date',
            'document_date' => 'date',
            'lines' => 'array',
            'field_values' => 'array',
            'approval' => 'array',
            'auto_reverse_on' => 'date',
            'source_references' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    /** The version of the transaction type this entry was made on, whatever its status now. */
    public function typeVersion(): TransactionTypeVersion
    {
        return TransactionTypeVersion::query()
            ->where('transaction_type_id', $this->transaction_type_id)
            ->where('version', $this->transaction_type_version)
            ->firstOrFail();
    }

    /** @return HasMany<EntryDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(EntryDecision::class, 'entry_id')->orderBy('created_at')->orderBy('id');
    }
}
