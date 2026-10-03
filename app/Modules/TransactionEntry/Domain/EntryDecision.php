<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * One step of the approval trail: who did what to an entry, when and why (TE-FR-09).
 *
 * @property string $id
 * @property string $entry_id
 * @property string $action
 * @property int|null $actor_id the user; null for an integration client or the system
 * @property string|null $client_id the integration client that took the step
 * @property string|null $reason
 * @property Carbon $created_at
 */
class EntryDecision extends CompanyScopedModel
{
    public const UPDATED_AT = null;

    protected $table = 'te_entry_decisions';

    protected $fillable = ['entry_id', 'action', 'actor_id', 'client_id', 'reason'];
}
