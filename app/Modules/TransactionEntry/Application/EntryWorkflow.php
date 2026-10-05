<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryDecision;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Access\ActorPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves an entry through its lifecycle (TE-FR-07): submit, approve, reject, revise, cancel and post.
 * Each step locks the entry, checks that the action applies to its status and records the decision
 * with actor, time and reason (TE-FR-09). Who may call an action is decided by the route permission.
 */
final class EntryWorkflow
{
    public function __construct(
        private readonly PostingValidator $validator,
        private readonly PostingService $posting,
        private readonly Audit $audit,
        private readonly ActorPermissions $permissions,
        private readonly EntryLines $lines,
        private readonly ApprovalPlanner $planner,
        private readonly EntryValidation $checks,
        private readonly EntryNumbers $numbers,
        private readonly EntryDocuments $documents,
    ) {}

    /**
     * Validates the entry against the ledger rules and sends it to approval by the plan the policy gives
     * it (TE-FR-08, TE-FR-09). An entry the policy approves automatically goes straight to `approved`, and
     * on to the ledger when the policy says so. An entry from a financial event is submitted by its client.
     *
     * @throws ApiException INVALID_STATE_TRANSITION, or any code of PostingValidator
     */
    public function submit(string $id, ?int $userId, ?string $clientId = null): Entry
    {
        return DB::transaction(function () use ($id, $userId, $clientId): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);

            $this->assertStatus($entry, [EntryStatus::Draft]);
            $this->checks->assertValid($entry);
            $this->refreshLines($entry);
            $this->validate($entry, $userId);

            $plan = $this->planner->plan($entry);
            $entry->forceFill([
                'status' => EntryStatus::Submitted,
                'approval' => $plan,
                // The type may number its entries only now (TE-FR-16); a number given at creation stays.
                'number' => $entry->number ?? $this->numbers->next($entry->transaction_type_key, CarbonImmutable::parse($entry->accounting_date->toDateString())),
            ])->save();
            $this->record($entry, 'submit', $userId, null, EntryStatus::Draft, $clientId);

            if ($plan['stages'] === []) {
                $entry->forceFill(['status' => EntryStatus::Approved])->save();
                $this->record($entry, 'auto_approve', $userId, null, EntryStatus::Submitted, $clientId);

                if ($plan['auto_post']) {
                    return $this->postLocked($entry, $userId, $clientId);
                }
            }

            return $entry->refresh();
        });
    }

    /**
     * Gives the next stage of the plan its approval. The actor must hold the permission of that stage,
     * must not be the creator when separation of duties is on, and must not have approved an earlier
     * stage of the same entry. After the last stage the entry is approved, and posted when the plan says so.
     *
     * @throws ApiException INVALID_STATE_TRANSITION, SEGREGATION_OF_DUTIES, FORBIDDEN
     */
    public function approve(string $id, int $userId): Entry
    {
        return DB::transaction(function () use ($id, $userId): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);

            $this->assertStatus($entry, [EntryStatus::Submitted]);

            $plan = $entry->approval ?? $this->planner->fallback();
            $stage = count($plan['approvals']);
            $permission = $plan['stages'][$stage] ?? ApprovalPlanner::DEFAULT_PERMISSION;

            if (! in_array($permission, $this->permissions->current(), true)) {
                throw new ApiException(ErrorCode::Forbidden, errors: ['permission' => [__('entries.stage_permission_missing', ['permission' => $permission])]]);
            }

            if (($plan['separation_of_duties'] && $entry->created_by === $userId) || in_array($userId, $plan['approvals'], true)) {
                throw new ApiException(ErrorCode::SegregationOfDuties);
            }

            $plan['approvals'][] = $userId;
            $last = count($plan['approvals']) >= count($plan['stages']);

            $entry->forceFill(['approval' => $plan, 'status' => $last ? EntryStatus::Approved : EntryStatus::Submitted])->save();
            $this->record($entry, 'approve', $userId, null, EntryStatus::Submitted);

            return $last && $plan['auto_post'] ? $this->postLocked($entry, $userId) : $entry->refresh();
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function reject(string $id, int $userId, string $reason): Entry
    {
        return $this->transition($id, [EntryStatus::Submitted], EntryStatus::Rejected, 'reject', $userId, $reason);
    }

    /** Takes a rejected entry back to draft so it can be corrected (TE-FR-07). */
    public function revise(string $id, int $userId): Entry
    {
        return $this->transition($id, [EntryStatus::Rejected], EntryStatus::Draft, 'revise', $userId);
    }

    /** Cancels an entry that will not be used; nothing is deleted. */
    public function cancel(string $id, int $userId, ?string $reason = null): Entry
    {
        return $this->transition($id, [EntryStatus::Draft, EntryStatus::Rejected], EntryStatus::Cancelled, 'cancel', $userId, $reason);
    }

    /**
     * Posts an approved entry to the ledger. Validation is repeated by the ledger inside the same
     * transaction (TE-FR-08); posting again returns the entry with the same journal (TE-FR-10).
     *
     * @throws ApiException INVALID_STATE_TRANSITION, or any code of PostingService
     */
    public function post(string $id, int $userId): Entry
    {
        return DB::transaction(function () use ($id, $userId): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);

            if ($entry->status === EntryStatus::Posted) {
                return $entry;
            }

            $this->assertStatus($entry, [EntryStatus::Approved]);

            return $this->postLocked($entry, $userId);
        });
    }

    /** Posts a locked, approved entry: the ledger validates again inside this transaction (TE-FR-08). */
    private function postLocked(Entry $entry, ?int $userId, ?string $clientId = null): Entry
    {
        $this->checks->assertValid($entry);
        $this->refreshLines($entry);

        $route = $this->documents->route($entry);

        // An entry with a subledger route records its document and posts through the subledger (TE-FR-10).
        $journal = match (true) {
            $entry->reverses_journal_id !== null => $this->posting->reverse($this->reversal($entry, $userId)),
            $route !== null => $this->documents->post($entry, $route, $this->command($entry, $userId, JournalSource::Subledger)),
            default => $this->posting->post($this->command($entry, $userId)),
        };

        $entry->forceFill([
            'status' => EntryStatus::Posted,
            'journal_id' => $journal->id,
            'journal_number' => $journal->number,
            'posted_at' => now(),
            // An entry that asked to be reversed on a date waits for that date from now on (TE-FR-11).
            'auto_reversal_status' => $entry->auto_reverse_on === null ? null : 'pending',
        ])->save();

        $this->record($entry, 'post', $userId, null, EntryStatus::Approved, $clientId);

        return $entry->refresh();
    }

    /**
     * @param  list<EntryStatus>  $from
     */
    private function transition(string $id, array $from, EntryStatus $to, string $action, int $userId, ?string $reason = null): Entry
    {
        return DB::transaction(function () use ($id, $from, $to, $action, $userId, $reason): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);
            $previous = $entry->status;

            $this->assertStatus($entry, $from);

            $entry->forceFill(['status' => $to])->save();
            $this->record($entry, $action, $userId, $reason, $previous);

            return $entry->refresh();
        });
    }

    /**
     * @param  list<EntryStatus>  $allowed
     *
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    private function assertStatus(Entry $entry, array $allowed): void
    {
        if (! in_array($entry->status, $allowed, true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                'status' => [__('entries.invalid_transition', ['status' => $entry->status->value])],
            ]);
        }
    }

    /**
     * The lines are settled when the entry is submitted and again when it is posted (TE-FR-08): a
     * structured entry takes them from the posting rules and role mappings as they stand then (TE-FR-05)
     * and foreign lines get the rate that applies then (TE-FR-06). A refusal rolls this back.
     */
    private function refreshLines(Entry $entry): void
    {
        $entry->forceFill(['lines' => $this->lines->of($entry)])->save();
    }

    /** Checks what the ledger, and the subledger for an entry with a route, will check when it posts the entry (TE-FR-08). */
    private function validate(Entry $entry, ?int $userId): void
    {
        $route = $this->documents->route($entry);

        match (true) {
            $entry->reverses_journal_id !== null => $this->validator->validateReversal($this->reversal($entry, $userId)),
            $route !== null => $this->documents->validate($entry, $route, $this->command($entry, $userId, JournalSource::Subledger)),
            default => $this->validator->validate($this->command($entry, $userId)),
        };
    }

    private function reversal(Entry $entry, ?int $userId): ReverseJournal
    {
        return new ReverseJournal(
            companyId: $entry->company_id,
            journalId: (string) $entry->reverses_journal_id,
            accountingDate: $entry->accounting_date->toDateString(),
            description: $entry->description,
            entryId: $entry->id,
            postedBy: $userId,
            actorPermissions: $this->permissions->current(),
        );
    }

    private function command(Entry $entry, ?int $userId, JournalSource $source = JournalSource::Entry): PostJournal
    {
        return new PostJournal(
            companyId: $entry->company_id,
            accountingDate: $entry->accounting_date->toDateString(),
            description: $entry->description,
            source: $source,
            lines: array_map(fn (array $line): PostJournalLine => new PostJournalLine(
                accountId: $line['account_id'],
                debit: $line['debit'],
                credit: $line['credit'],
                description: $line['description'],
                currency: $line['currency'] ?? null,
                dimensions: $line['dimensions'] ?? [],
                exchangeRate: $line['exchange_rate'] ?? null,
                rateTypeKey: $line['rate_type_key'] ?? null,
            ), $entry->lines),
            documentDate: $entry->document_date?->toDateString(),
            transactionTypeKey: $entry->transaction_type_key,
            transactionTypeVersion: $entry->transaction_type_version,
            references: ['entry_id' => $entry->id, 'entry_number' => $entry->number],
            entryId: $entry->id,
            postedBy: $userId,
            // The ledger tells which of them it cares about (soft-closed periods, GL-FR-04).
            actorPermissions: $this->permissions->current(),
        );
    }

    private function record(Entry $entry, string $action, ?int $userId, ?string $reason, EntryStatus $previous, ?string $clientId = null): void
    {
        EntryDecision::record($entry, $action, $userId, $reason, $clientId);

        $this->audit->record(
            "entry.{$action}",
            'Entry '.($entry->number ?? $entry->id).": {$action}.",
            $entry,
            before: ['status' => $previous->value],
            after: ['status' => $entry->status->value],
            properties: $reason === null ? [] : ['reason' => $reason],
            companyId: $entry->company_id,
        );
    }
}
