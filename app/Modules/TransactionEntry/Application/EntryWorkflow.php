<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryDecision;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
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
    ) {}

    /**
     * Validates the entry against the ledger rules and sends it to approval (TE-FR-08).
     *
     * @throws ApiException INVALID_STATE_TRANSITION, or any code of PostingValidator
     */
    public function submit(string $id, int $userId): Entry
    {
        return $this->transition($id, [EntryStatus::Draft], EntryStatus::Submitted, 'submit', $userId, validate: true);
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION, SEGREGATION_OF_DUTIES
     */
    public function approve(string $id, int $userId): Entry
    {
        return $this->transition($id, [EntryStatus::Submitted], EntryStatus::Approved, 'approve', $userId);
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

            $journal = $this->posting->post($this->command($entry, $userId));

            $entry->forceFill(['status' => EntryStatus::Posted, 'journal_id' => $journal->id, 'journal_number' => $journal->number, 'posted_at' => now()])->save();

            $this->record($entry, 'post', $userId, null, EntryStatus::Approved);

            return $entry->refresh();
        });
    }

    /**
     * @param  list<EntryStatus>  $from
     */
    private function transition(string $id, array $from, EntryStatus $to, string $action, int $userId, ?string $reason = null, bool $validate = false): Entry
    {
        return DB::transaction(function () use ($id, $from, $to, $action, $userId, $reason, $validate): Entry {
            $entry = Entry::query()->lockForUpdate()->findOrFail($id);
            $previous = $entry->status;

            $this->assertStatus($entry, $from);

            if ($action === 'approve' && config('entries.separation_of_duties') && $entry->created_by === $userId) {
                throw new ApiException(ErrorCode::SegregationOfDuties);
            }

            if ($validate) {
                $this->validator->validate($this->command($entry, $userId));
            }

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

    private function command(Entry $entry, int $userId): PostJournal
    {
        return new PostJournal(
            companyId: $entry->company_id,
            accountingDate: $entry->accounting_date->toDateString(),
            description: $entry->description,
            source: JournalSource::Entry,
            lines: array_map(fn (array $line): PostJournalLine => new PostJournalLine(
                accountId: $line['account_id'],
                debit: $line['debit'],
                credit: $line['credit'],
                description: $line['description'],
            ), $entry->lines),
            documentDate: $entry->document_date?->toDateString(),
            transactionTypeKey: $entry->transaction_type_key,
            transactionTypeVersion: $entry->transaction_type_version,
            references: ['entry_id' => $entry->id, 'entry_number' => $entry->number],
            entryId: $entry->id,
            postedBy: $userId,
        );
    }

    private function record(Entry $entry, string $action, int $userId, ?string $reason, EntryStatus $previous): void
    {
        EntryDecision::create(['entry_id' => $entry->id, 'action' => $action, 'actor_id' => $userId, 'reason' => $reason]);

        $this->audit->record(
            "entry.{$action}",
            "Entry {$entry->number}: {$action}.",
            $entry,
            before: ['status' => $previous->value],
            after: ['status' => $entry->status->value],
            properties: $reason === null ? [] : ['reason' => $reason],
            companyId: $entry->company_id,
        );
    }
}
