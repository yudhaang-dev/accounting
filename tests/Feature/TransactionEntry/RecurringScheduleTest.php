<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\TransactionEntry\Application\RecurringSchedules;
use App\Support\Company\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Recurring journals (TE-FR-14).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->preparer = memberWithRoles($this->companyId, 'preparer');
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->expense = rsAccountId('6310');
    $this->accrued = rsAccountId('1150');

    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
});

function rsAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function rsPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Amortisasi sewa',
        'transaction_type_key' => 'general_journal',
        'frequency' => 'monthly',
        'start_date' => '2026-01-31',
        'template' => [
            'description' => 'Amortisasi sewa dibayar di muka',
            'lines' => [['account_id' => test()->expense, 'debit' => '1000.00'], ['account_id' => test()->accrued, 'credit' => '1000.00']],
        ],
    ], $overrides);
}

function rsCreate(array $overrides = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->preparer)->postJson(test()->url.'/recurring-schedules', rsPayload($overrides));
}

function rsRun(string $at)
{
    return app(RecurringSchedules::class)->runDue(CarbonImmutable::parse($at));
}

function rsEntries(?string $scheduleId = null)
{
    return DB::table('te_entries')->where('company_id', test()->companyId)->where('source', 'recurring')
        ->when($scheduleId !== null, fn ($query) => $query->where('recurring_schedule_id', $scheduleId))->orderBy('recurrence_date')->get();
}

describe('the schedule', function () {
    it('is created waiting at its start date', function () {
        $schedule = rsCreate()->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.next_run_on', '2026-01-31')
            ->assertJsonPath('data.initial_status', 'draft')->assertJsonPath('data.created_by', $this->preparer->id)->json('data');

        $this->actingAs($this->viewer)->getJson("{$this->url}/recurring-schedules/{$schedule['id']}")->assertOk()->assertJsonPath('data.name', 'Amortisasi sewa');
        $this->actingAs($this->viewer)->getJson("{$this->url}/recurring-schedules")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->viewer)->postJson("{$this->url}/recurring-schedules", rsPayload())->assertForbidden();
    });

    it('refuses what does not hold', function () {
        $cases = [
            'an unknown frequency' => [['frequency' => 'hourly'], 'frequency'],
            'a template without description' => [['template' => ['lines' => []]], 'template.description'],
            'an end before the start' => [['end_date' => '2026-01-01'], 'end_date'],
            'an interval of zero' => [['interval_count' => 0], 'interval_count'],
            'an unknown transaction type' => [['transaction_type_key' => 'nope'], 'transaction_type_key'],
            'an unknown version' => [['transaction_type_version' => 9], 'transaction_type_version'],
        ];

        foreach ($cases as $name => [$overrides, $field]) {
            $response = rsCreate($overrides);

            expect($response->status())->toBe(422, $name)->and(array_keys($response->json('errors')))->toContain($field);
        }

        expect(DB::table('te_recurring_schedules')->count())->toBe(0);
    });

    it('changes only through its lock version', function () {
        $schedule = rsCreate()->assertCreated()->json('data');
        $body = ['name' => 'Renamed', 'template' => rsPayload()['template'], 'status' => 'paused'];

        $this->actingAs($this->preparer)->putJson("{$this->url}/recurring-schedules/{$schedule['id']}", $body + ['lock_version' => 99])->assertConflict();
        $this->actingAs($this->preparer)->putJson("{$this->url}/recurring-schedules/{$schedule['id']}", $body + ['lock_version' => $schedule['lock_version']])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'paused');
    });
});

describe('running (TE-FR-14)', function () {
    it('makes the entry of a date once it has come, in the time zone of the company', function () {
        $schedule = rsCreate()->assertCreated()->json('data');

        expect(rsRun('2026-01-30 12:00:00'))->toBe(['created' => 0, 'failed' => 0])->and(rsEntries())->toHaveCount(0);

        // 18:00 UTC on the 30th is already the 31st in Jakarta (UTC+7).
        expect(rsRun('2026-01-30 18:00:00'))->toBe(['created' => 1, 'failed' => 0]);

        $entry = rsEntries($schedule['id'])->first();

        expect($entry->accounting_date)->toBe('2026-01-31')->and($entry->status)->toBe('draft')->and($entry->created_by)->toBe($this->preparer->id)
            ->and($entry->recurrence_date)->toBe('2026-01-31');

        $after = $this->actingAs($this->preparer)->getJson("{$this->url}/recurring-schedules/{$schedule['id']}")->json('data');

        expect($after)->toMatchArray(['next_run_on' => '2026-02-28', 'last_run_on' => '2026-01-31', 'last_entry_id' => $entry->id, 'last_error' => null]);
    });

    it('keeps to the month end, whatever the length of the months', function () {
        $schedule = rsCreate()->assertCreated()->json('data');

        rsRun('2026-04-30 12:00:00');

        expect(rsEntries($schedule['id'])->pluck('recurrence_date')->all())->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
    });

    it('does not make a date twice', function () {
        rsCreate()->assertCreated();

        expect(rsRun('2026-01-31 12:00:00'))->toBe(['created' => 1, 'failed' => 0])
            ->and(rsRun('2026-01-31 13:00:00'))->toBe(['created' => 0, 'failed' => 0])
            ->and(rsEntries())->toHaveCount(1);
    });

    it('holds the unique date in the database too', function () {
        $schedule = rsCreate()->assertCreated()->json('data');
        rsRun('2026-01-31 12:00:00');

        $row = (array) DB::table('te_entries')->where('recurring_schedule_id', $schedule['id'])->first();
        $row['id'] = (string) Str::ulid();
        $row['number'] = 'DUP-1';

        expect(fn () => DB::table('te_entries')->insert($row))->toThrow(UniqueConstraintViolationException::class);
    });

    it('supports the other frequencies and intervals', function () {
        $weekly = rsCreate(['frequency' => 'weekly', 'interval_count' => 2, 'start_date' => '2026-03-02'])->assertCreated()->json('data');
        $quarterly = rsCreate(['name' => 'Q', 'frequency' => 'quarterly', 'start_date' => '2026-01-15'])->assertCreated()->json('data');

        rsRun('2026-04-20 12:00:00');

        expect(rsEntries($weekly['id'])->pluck('recurrence_date')->all())->toBe(['2026-03-02', '2026-03-16', '2026-03-30', '2026-04-13'])
            ->and(rsEntries($quarterly['id'])->pluck('recurrence_date')->all())->toBe(['2026-01-15', '2026-04-15']);
    });

    it('completes after the end date and makes nothing more', function () {
        $schedule = rsCreate(['end_date' => '2026-02-28'])->assertCreated()->json('data');

        rsRun('2026-06-01 12:00:00');

        $after = $this->actingAs($this->preparer)->getJson("{$this->url}/recurring-schedules/{$schedule['id']}")->json('data');

        expect(rsEntries())->toHaveCount(2)->and($after['status'])->toBe('completed')->and($after['next_run_on'])->toBeNull();
    });

    it('makes nothing while paused and goes on from where it was when resumed', function () {
        $schedule = rsCreate()->assertCreated()->json('data');
        $paused = $this->actingAs($this->preparer)->putJson("{$this->url}/recurring-schedules/{$schedule['id']}", ['name' => 'x', 'template' => rsPayload()['template'], 'status' => 'paused', 'lock_version' => $schedule['lock_version']])->json('data');

        expect(rsRun('2026-03-01 12:00:00'))->toBe(['created' => 0, 'failed' => 0]);

        $this->actingAs($this->preparer)->putJson("{$this->url}/recurring-schedules/{$schedule['id']}", ['name' => 'x', 'template' => rsPayload()['template'], 'status' => 'active', 'lock_version' => $paused['lock_version']])->assertOk();

        expect(rsRun('2026-03-01 12:00:00'))->toBe(['created' => 2, 'failed' => 0]);
    });

    it('leaves the entries submitted and under the normal approval when asked', function () {
        $schedule = rsCreate(['initial_status' => 'submitted'])->assertCreated()->json('data');

        rsRun('2026-01-31 12:00:00');

        $entry = rsEntries($schedule['id'])->first();
        $detail = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$entry->id}")->assertOk()->json('data');

        expect($detail['status'])->toBe('submitted')->and($detail['source'])->toBe('recurring')->and($detail['lines'])->toHaveCount(2)
            ->and($detail['decisions'][0]['action'])->toBe('submit');

        $approver = memberWithRoles($this->companyId, 'approver');
        $this->actingAs($approver)->postJson("{$this->url}/entries/{$entry->id}/approve")->assertOk();
        $this->actingAs($approver)->postJson("{$this->url}/entries/{$entry->id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    });

    it('stops at a date that cannot be made, shows why, and does not make later dates ahead of it', function () {
        $schedule = rsCreate(['initial_status' => 'submitted'])->assertCreated()->json('data');

        $january = DB::table('gl_periods')->where('company_id', $this->companyId)->where('start_date', '<=', '2026-01-31')->where('end_date', '>=', '2026-01-31')->value('id');
        DB::table('gl_periods')->where('id', $january)->update(['status' => 'closed']);

        expect(rsRun('2026-03-15 12:00:00'))->toBe(['created' => 0, 'failed' => 1])->and(rsEntries())->toHaveCount(0);

        $stuck = $this->actingAs($this->preparer)->getJson("{$this->url}/recurring-schedules/{$schedule['id']}")->json('data');

        expect($stuck['next_run_on'])->toBe('2026-01-31')->and($stuck['last_error'])->not->toBeNull()->and($stuck['last_error_at'])->not->toBeNull();

        // Still failing on the next run: visible again, and nothing was made behind it.
        expect(rsRun('2026-03-15 13:00:00'))->toBe(['created' => 0, 'failed' => 1]);

        $this->actingAs($this->preparer)->getJson("{$this->url}/recurring-schedules?filter[status]=active")->assertOk()->assertJsonPath('data.0.last_error', fn ($error) => $error !== null);

        // Someone gives the date up, and the schedule goes on to the dates that can be made.
        $skipped = $this->actingAs($this->preparer)->postJson("{$this->url}/recurring-schedules/{$schedule['id']}/skip")->assertOk()
            ->assertJsonPath('data.next_run_on', '2026-02-28')->assertJsonPath('data.last_error', null)->json('data');

        expect($skipped['last_run_on'])->toBeNull()
            ->and(rsRun('2026-03-15 14:00:00'))->toBe(['created' => 1, 'failed' => 0])
            ->and(rsEntries()->pluck('recurrence_date')->all())->toBe(['2026-02-28']);
    });

    it('records nothing of an occurrence that failed half way', function () {
        $schedule = rsCreate(['initial_status' => 'submitted', 'template' => ['description' => 'Tak seimbang', 'lines' => [['account_id' => $this->expense, 'debit' => '1000.00'], ['account_id' => $this->accrued, 'credit' => '900.00']]]])->assertCreated()->json('data');

        expect(rsRun('2026-01-31 12:00:00'))->toBe(['created' => 0, 'failed' => 1])->and(rsEntries())->toHaveCount(0);

        expect(DB::table('te_recurring_schedules')->where('id', $schedule['id'])->value('last_error'))->not->toBeNull();
    });

    it('cannot skip when nothing is waiting', function () {
        $schedule = rsCreate(['end_date' => '2026-01-31'])->assertCreated()->json('data');
        rsRun('2026-01-31 12:00:00');

        $this->actingAs($this->preparer)->postJson("{$this->url}/recurring-schedules/{$schedule['id']}/skip")->assertStatus(409);
    });
});
