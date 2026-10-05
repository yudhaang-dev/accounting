<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\JournalDirectory;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\EntryDecision;
use App\Modules\TransactionEntry\Domain\EntryStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Access\ActorPermissions;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reversal of posted journals (TE-FR-11). A reversal is an entry of the type `system.reversal` that goes
 * through the approval policy like any other and, once approved, makes the ledger post the mirror image
 * of the journal in full. The same entry type carries the reversals the system makes on the date an entry
 * asked for (`auto_reverse_on`), which stand on the approval of the entry they reverse.
 */
final class EntryReversals
{
    public function __construct(
        private readonly JournalDirectory $journals,
        private readonly EntryDocuments $documents,
        private readonly SystemTransactionTypes $types,
        private readonly EntryNumbers $numbers,
        private readonly EntryWorkflow $workflow,
        private readonly Audit $audit,
        private readonly ActorPermissions $permissions,
        private readonly CompanyContext $company,
        private readonly CompanyDirectory $companies,
    ) {}

    /**
     * Starts a reversal of a posted journal as a draft entry. The date must fall in a period that accepts
     * the posting; that is checked again on submit and on posting.
     *
     * @throws ApiException NOT_FOUND (unknown journal, or one the caller does not see entirely), INVALID_STATE_TRANSITION (reversed already, or a reversal is under way), PERIOD_NOT_OPEN
     * @throws ValidationException
     */
    public function request(string $journalId, string $date, string $reason, int $userId): Entry
    {
        $companyId = $this->company->require();
        $journal = $this->journals->find($companyId, $journalId);

        if ($journal === null || ! $journal->withinScope) {
            throw new ApiException(ErrorCode::NotFound);
        }

        if ($journal->reversed) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['journal_id' => [__('entries.journal_reversed', ['number' => $journal->number])]]);
        }

        $this->documents->validateReversal(new ReverseJournal($companyId, $journalId, $date, actorPermissions: $this->permissions->current()));

        try {
            return DB::transaction(function () use ($journal, $date, $reason, $userId): Entry {
                $type = $this->types->ensure(SystemTransactionTypes::REVERSAL, 'Pembalikan');

                $entry = Entry::create([
                    'transaction_type_id' => $type->id,
                    'transaction_type_key' => $type->key,
                    'transaction_type_version' => 1,
                    'number' => $this->numbers->next($type->key, CarbonImmutable::parse($date)),
                    'accounting_date' => $date,
                    'description' => Str::limit(__('entries.reversal_description', ['number' => $journal->number, 'reason' => $reason]), 500, ''),
                    'lines' => [],
                    'reverses_journal_id' => $journal->id,
                    'reason' => $reason,
                    'created_by' => $userId,
                ])->refresh();

                $this->audit->record('entry.created', "Reversal {$entry->number} of journal {$journal->number} created.", $entry, after: ['number' => $entry->number, 'status' => $entry->status->value], properties: ['reason' => $reason], companyId: $entry->company_id);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request for the same journal is under way.
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['journal_id' => [__('entries.reversal_exists', ['number' => $journal->number])]]);
        }
    }

    /**
     * Reverses the posted entries whose `auto_reverse_on` has come, on the approval they were posted with.
     * An entry whose reversal cannot be posted (a period that no longer accepts it, for one) is marked
     * `failed` with the reason and stays that way until someone reverses it by hand; it is never skipped
     * silently (TE-AC-09).
     *
     * @return array{done: int, failed: int}
     */
    public function runScheduled(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $done = 0;
        $failed = 0;

        // The date is the company's, whose time zone may be a day ahead of UTC.
        $due = Entry::withoutGlobalScopes()
            ->where('auto_reversal_status', 'pending')
            ->where('auto_reverse_on', '<=', $now->addDay()->toDateString())
            ->orderBy('auto_reverse_on')->orderBy('id')
            ->get(['id', 'company_id', 'auto_reverse_on']);

        foreach ($due as $row) {
            $zone = $this->companies->find($row->company_id)->timezone ?? 'UTC';

            if ($row->auto_reverse_on->toDateString() > $now->setTimezone($zone)->toDateString()) {
                continue;
            }

            $this->company->run($row->company_id, fn (): string => $this->reverseOne($row->id)) === 'done' ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** @return 'done'|'failed' */
    private function reverseOne(string $originId): string
    {
        $origin = Entry::query()->findOrFail($originId);

        try {
            DB::transaction(function () use ($origin): void {
                $actor = $origin->approval['approvals'][0] ?? $origin->created_by;
                $type = $this->types->ensure(SystemTransactionTypes::REVERSAL, 'Pembalikan');
                $reason = (string) __('entries.auto_reversal_reason', ['number' => $origin->number]);

                $reversal = Entry::create([
                    'transaction_type_id' => $type->id,
                    'transaction_type_key' => $type->key,
                    'transaction_type_version' => 1,
                    'number' => $this->numbers->next($type->key, CarbonImmutable::parse($origin->auto_reverse_on?->toDateString())),
                    'status' => EntryStatus::Approved,
                    'accounting_date' => $origin->auto_reverse_on,
                    'description' => Str::limit(__('entries.reversal_description', ['number' => $origin->journal_number, 'reason' => $reason]), 500, ''),
                    'lines' => [],
                    'reverses_journal_id' => $origin->journal_id,
                    'reason' => $reason,
                    'created_by' => $actor,
                ])->refresh();

                EntryDecision::record($reversal, 'auto_approve', $actor, $reason);

                $this->workflow->post($reversal->id, $actor);

                $origin->forceFill(['auto_reversal_status' => 'done', 'auto_reversal_error' => null, 'auto_reversal_entry_id' => $reversal->id])->save();
            });
        } catch (ApiException|ValidationException $e) {
            $message = $e instanceof ApiException ? $this->describe($e) : $e->getMessage();

            $origin->forceFill(['auto_reversal_status' => 'failed', 'auto_reversal_error' => Str::limit($message, 500, '')])->save();
            $this->audit->record('entry.auto_reversal_failed', "Scheduled reversal of entry {$origin->number} failed.", $origin, properties: ['error' => $message], companyId: $origin->company_id);
            Log::warning("Scheduled reversal of entry {$origin->number} failed: {$message}");

            return 'failed';
        }

        return 'done';
    }

    private function describe(ApiException $e): string
    {
        $details = collect($e->errors)->flatten()->implode(' ');

        return trim("{$e->errorCode->value}: {$e->getMessage()} {$details}");
    }
}
