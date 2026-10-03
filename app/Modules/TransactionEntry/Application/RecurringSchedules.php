<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\RecurringSchedule;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Recurring journals (TE-FR-14). A schedule holds a template of fixed values; on every date it gives, in
 * the time zone of the company, the system makes an entry from it, which is validated and approved like
 * any other. A date that cannot be made (a closed period, an account that is no longer open) stops the
 * schedule at that date with the reason on it, and it is retried on every run until the cause is gone or
 * someone skips the date; later dates are never made ahead of it, and nothing is skipped silently.
 *
 * @phpstan-type Content array{name: string, template: array<string, mixed>, transaction_type_version?: int|null, end_date?: string|null, initial_status?: string, status?: string}
 */
final class RecurringSchedules
{
    /** What one run makes for one schedule at most, so a schedule far in the past cannot hold the run for long. */
    private const MAX_PER_RUN = 62;

    public function __construct(
        private readonly EntryDrafts $drafts,
        private readonly EntryWorkflow $workflow,
        private readonly CompanyContext $company,
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{name: string, template: array<string, mixed>, transaction_type_key: string, frequency: string, interval_count?: int, start_date: string, transaction_type_version?: int|null, end_date?: string|null, initial_status?: string}  $data
     *
     * @throws ValidationException when the transaction type or its version cannot be used
     */
    public function create(array $data, int $userId): RecurringSchedule
    {
        return DB::transaction(function () use ($data, $userId): RecurringSchedule {
            $this->assertUsable($data['transaction_type_key'], $data['transaction_type_version'] ?? null);

            $schedule = RecurringSchedule::create([
                'name' => $data['name'],
                'transaction_type_key' => $data['transaction_type_key'],
                'transaction_type_version' => $data['transaction_type_version'] ?? null,
                'template' => $data['template'],
                'frequency' => $data['frequency'],
                'interval_count' => $data['interval_count'] ?? 1,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'initial_status' => $data['initial_status'] ?? 'draft',
                'status' => 'active',
                'run_count' => 0,
                'created_by' => $userId,
            ]);

            $this->settle($schedule);
            $schedule->save();

            $this->audit->record('recurring_schedule.created', "Recurring schedule {$schedule->name} created.", $schedule, after: ['next_run_on' => $schedule->next_run_on?->toDateString()], companyId: $schedule->company_id);

            return $schedule->refresh();
        });
    }

    /**
     * Changes what the next entries are made from and when the schedule ends or pauses. How often it runs
     * and where it starts stay as they were: the dates already made hang on them.
     *
     * @param  Content  $data
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $id, array $data, int $expectedVersion): RecurringSchedule
    {
        return DB::transaction(function () use ($id, $data, $expectedVersion): RecurringSchedule {
            $schedule = RecurringSchedule::query()->lockForUpdate()->findOrFail($id);

            if ((int) $schedule->lock_version !== $expectedVersion) {
                throw new ApiException(ErrorCode::VersionConflict);
            }

            $this->assertUsable($schedule->transaction_type_key, $data['transaction_type_version'] ?? null);

            $schedule->fill([
                'name' => $data['name'],
                'template' => $data['template'],
                'transaction_type_version' => $data['transaction_type_version'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'initial_status' => $data['initial_status'] ?? $schedule->initial_status,
                'status' => $data['status'] ?? ($schedule->status === 'paused' ? 'paused' : 'active'),
            ]);

            $this->settle($schedule);
            $schedule->save();

            $this->audit->record('recurring_schedule.updated', "Recurring schedule {$schedule->name} updated.", $schedule, after: ['status' => $schedule->status, 'next_run_on' => $schedule->next_run_on?->toDateString()], companyId: $schedule->company_id);

            return $schedule->refresh();
        });
    }

    /**
     * Gives up the date the schedule is waiting at and goes on to the next one. The date stays on record
     * in the audit log.
     *
     * @throws ApiException INVALID_STATE_TRANSITION when no date is waiting
     */
    public function skip(string $id): RecurringSchedule
    {
        return DB::transaction(function () use ($id): RecurringSchedule {
            $schedule = RecurringSchedule::query()->lockForUpdate()->findOrFail($id);

            if ($schedule->next_run_on === null) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['status' => [__('entries.schedule_nothing_to_skip')]]);
            }

            $skipped = $schedule->next_run_on->toDateString();
            $this->advance($schedule);
            $schedule->save();

            $this->audit->record('recurring_schedule.skipped', "Occurrence {$skipped} of {$schedule->name} skipped.", $schedule, properties: ['occurrence' => $skipped, 'reason' => $schedule->last_error], companyId: $schedule->company_id);

            return $schedule->refresh();
        });
    }

    /**
     * Makes the entries of every active schedule whose date has come, in the time zone of its company.
     *
     * @return array{created: int, failed: int}
     */
    public function runDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $created = 0;
        $failed = 0;

        // The date is the company's, whose time zone may be a day ahead of UTC.
        $due = RecurringSchedule::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('next_run_on', '<=', $now->addDay()->toDateString())
            ->orderBy('next_run_on')->orderBy('id')
            ->get(['id', 'company_id']);

        foreach ($due as $row) {
            $zone = $this->companies->find($row->company_id)->timezone ?? 'UTC';
            $today = $now->setTimezone($zone)->toDateString();

            $result = $this->company->run($row->company_id, fn (): array => $this->runSchedule($row->id, $today));
            $created += $result['created'];
            $failed += $result['failed'];
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** @return array{created: int, failed: int} */
    private function runSchedule(string $id, string $today): array
    {
        $created = 0;

        for ($i = 0; $i < self::MAX_PER_RUN; $i++) {
            try {
                $made = DB::transaction(function () use ($id, $today): bool {
                    $schedule = RecurringSchedule::query()->lockForUpdate()->findOrFail($id);

                    if ($schedule->status !== 'active' || $schedule->next_run_on === null || $schedule->next_run_on->toDateString() > $today) {
                        return false;
                    }

                    $this->makeEntry($schedule, $schedule->next_run_on->toDateString());
                    $this->advance($schedule, madeOn: $schedule->next_run_on->toDateString());
                    $schedule->save();

                    return true;
                });
            } catch (UniqueConstraintViolationException) {
                // The entry for this date exists already (another run got there first): the date is done.
                $this->failOrAdvance($id, null);

                continue;
            } catch (ValidationException|ApiException $e) {
                $this->failOrAdvance($id, $this->reason($e));

                return ['created' => $created, 'failed' => 1];
            }

            if (! $made) {
                break;
            }

            $created++;
        }

        return ['created' => $created, 'failed' => 0];
    }

    /** Makes the entry of an occurrence in the transaction of the caller, so that a refusal leaves nothing behind. */
    private function makeEntry(RecurringSchedule $schedule, string $date): void
    {
        /** @var array{description: string, document_date?: string|null, currency?: string|null, exchange_rate?: string|null, lines?: list<array{account_id: string}>, values?: array<string, mixed>} $template */
        $template = $schedule->template;

        $entry = $this->drafts->create($schedule->transaction_type_key, $schedule->transaction_type_version, [...$template, 'accounting_date' => $date], $schedule->created_by, [
            'source' => 'recurring',
            'recurring_schedule_id' => $schedule->id,
            'recurrence_date' => $date,
        ]);

        if ($schedule->initial_status === 'submitted') {
            $entry = $this->workflow->submit($entry->id, $schedule->created_by);
        }

        $schedule->last_entry_id = $entry->id;
    }

    /** Moves the schedule past the date it waits at. `$madeOn` is set when an entry was made for it. */
    private function advance(RecurringSchedule $schedule, ?string $madeOn = null): void
    {
        $schedule->run_count++;
        $schedule->last_error = null;
        $schedule->last_error_at = null;

        if ($madeOn !== null) {
            $schedule->forceFill(['last_run_on' => $madeOn]);
        }

        $this->settle($schedule);
    }

    /** Where the schedule stands from its count: waiting at the next date, or completed once the end date is passed. */
    private function settle(RecurringSchedule $schedule): void
    {
        if ($schedule->status === 'paused') {
            $schedule->forceFill(['next_run_on' => $schedule->nextOccurrence()?->toDateString()]);

            return;
        }

        $next = $schedule->nextOccurrence();
        $schedule->forceFill(['next_run_on' => $next?->toDateString(), 'status' => $next === null ? 'completed' : 'active']);
    }

    /** Puts the reason on the schedule, in a transaction of its own because the one that failed is gone. Without a reason, the date is skipped as made. */
    private function failOrAdvance(string $id, ?string $reason): void
    {
        DB::transaction(function () use ($id, $reason): void {
            $schedule = RecurringSchedule::query()->lockForUpdate()->findOrFail($id);

            if ($reason === null) {
                $this->advance($schedule, madeOn: $schedule->next_run_on?->toDateString());
            } else {
                $schedule->forceFill(['last_error' => $reason, 'last_error_at' => now()]);
                $this->audit->record('recurring_schedule.failed', "Occurrence {$schedule->next_run_on?->toDateString()} of {$schedule->name} failed.", $schedule, properties: ['reason' => $reason], companyId: $schedule->company_id);
                Log::warning("Recurring schedule {$schedule->id} failed at {$schedule->next_run_on?->toDateString()}: {$reason}");
            }

            $schedule->save();
        });
    }

    private function reason(Throwable $e): string
    {
        $errors = $e instanceof ValidationException ? $e->errors() : ($e instanceof ApiException ? $e->errors : []);
        $details = [];

        foreach ($errors as $field => $messages) {
            $details[] = "{$field}: ".implode(' ', $messages);
        }

        $code = $e instanceof ApiException ? "[{$e->errorCode->value}] " : '';

        return $code.($details === [] ? $e->getMessage() : implode('; ', $details));
    }

    /** @throws ValidationException */
    private function assertUsable(string $key, ?int $version): void
    {
        $type = TransactionType::query()->where('key', $key)->first()
            ?? throw ValidationException::withMessages(['transaction_type_key' => [__('entries.type_unknown', ['key' => $key])]]);

        $this->drafts->version($type, $version);
    }
}
