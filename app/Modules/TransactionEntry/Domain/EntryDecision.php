<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One step of the approval trail: who did what to an entry, when and why (TE-FR-09).
 *
 * @property string $id
 * @property string $entry_id
 * @property string $action
 * @property int|null $actor_id the user; null for an integration client or the system
 * @property string|null $client_id the integration client that took the step
 * @property string|null $reason
 * @property int $seq the number of the step in the company, in the order the steps become visible
 * @property string $status the status of the entry after the step
 * @property Carbon $created_at
 */
class EntryDecision extends CompanyScopedModel
{
    public const UPDATED_AT = null;

    protected $table = 'te_entry_decisions';

    protected $fillable = ['entry_id', 'action', 'actor_id', 'client_id', 'reason', 'seq', 'status'];

    /**
     * Records a step the entry has just taken and gives it the next number of the company (TE-FR-15). The
     * counter row stays locked until the transaction ends, so the numbers of the company reach readers in
     * the order they were given.
     */
    public static function record(Entry $entry, string $action, ?int $actorId, ?string $reason = null, ?string $clientId = null): self
    {
        /** @var object{last: int} $counter */
        $counter = DB::selectOne(
            'insert into te_decision_sequences (company_id, last) values (?, 1) on conflict (company_id) do update set last = te_decision_sequences.last + 1 returning last',
            [$entry->company_id],
        );

        return self::create([
            'entry_id' => $entry->id, 'action' => $action, 'actor_id' => $actorId, 'client_id' => $clientId, 'reason' => $reason,
            'seq' => (int) $counter->last, 'status' => $entry->status->value,
        ]);
    }
}
