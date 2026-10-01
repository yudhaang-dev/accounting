<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\AuditEntry;
use App\Platform\Retention\RetentionGuard;
use App\Support\Company\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

function calendarUrl(string $path = ''): string
{
    return test()->url."/fiscal-calendar{$path}";
}

function createCalendar(int $startMonth = 1): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(calendarUrl(), ['pattern' => 'monthly', 'start_month' => $startMonth]);
}

function createYears(array $body = ['start_date' => '2026-01-01']): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/fiscal-years', $body);
}

describe('fiscal calendar (GL-FR-03)', function () {
    it('is absent until it is set up, then created once', function () {
        $this->actingAs($this->admin)->getJson(calendarUrl())->assertNotFound();

        createCalendar(7)->assertCreated()->assertJson(['data' => ['pattern' => 'monthly', 'start_month' => 7, 'lock_version' => 1]]);
        $this->actingAs($this->admin)->getJson(calendarUrl())->assertOk()->assertJsonPath('data.start_month', 7);

        createCalendar()->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);

        expect(AuditEntry::where('event', 'fiscal_calendar.created')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('validates the pattern and the start month', function () {
        $this->actingAs($this->admin)->postJson(calendarUrl(), ['pattern' => 'weekly', 'start_month' => 13])
            ->assertUnprocessable()->assertJsonValidationErrors(['pattern', 'start_month']);
    });

    it('can change with lock_version while no fiscal year exists', function () {
        createCalendar(1)->assertCreated();

        $this->actingAs($this->admin)->patchJson(calendarUrl(), ['lock_version' => 1, 'start_month' => 4])
            ->assertOk()->assertJsonPath('data.start_month', 4)->assertJsonPath('data.lock_version', 2);
        $this->actingAs($this->admin)->patchJson(calendarUrl(), ['lock_version' => 1, 'start_month' => 5])
            ->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);

        expect(AuditEntry::where('event', 'fiscal_calendar.updated')->count())->toBe(1);
    });

    it('cannot change once fiscal years exist, except to the same values', function () {
        createCalendar(1)->assertCreated();
        createYears()->assertCreated();

        $this->actingAs($this->admin)->patchJson(calendarUrl(), ['lock_version' => 1, 'start_month' => 4])
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);
        $this->actingAs($this->admin)->patchJson(calendarUrl(), ['lock_version' => 1, 'start_month' => 1])->assertOk();
    });

    it('is managed with periods.manage and read with periods.read', function () {
        createCalendar()->assertCreated();
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson(calendarUrl())->assertOk();
        $this->actingAs($viewer)->patchJson(calendarUrl(), ['lock_version' => 1, 'start_month' => 2])->assertForbidden();
        $this->actingAs(memberWithRoles($this->companyId))->getJson(calendarUrl())->assertForbidden();
    });
});

describe('fiscal years and periods (GL-FR-03)', function () {
    it('needs a fiscal calendar first', function () {
        createYears()->assertUnprocessable()->assertJson(['code' => 'MAPPING_REQUIRED']);
    });

    it('needs a start date for the first year, on the 1st of the calendar start month', function () {
        createCalendar(7)->assertCreated();

        createYears([])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
        createYears(['start_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
        createYears(['start_date' => '2026-07-15'])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
        createYears(['start_date' => '01-07-2026'])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        expect(DB::table('gl_fiscal_years')->count())->toBe(0);
    });

    it('creates twelve contiguous monthly periods, all open, without an opening journal', function () {
        createCalendar()->assertCreated();

        $response = createYears()->assertCreated();
        $periods = $response->json('data.0.periods');

        expect($response->json('data.0.name'))->toBe('FY2026')
            ->and($response->json('data.0.start_date'))->toBe('2026-01-01')
            ->and($response->json('data.0.end_date'))->toBe('2026-12-31')
            ->and($response->json('data.0.period_count'))->toBe(12)
            ->and($periods)->toHaveCount(12)
            ->and(array_column($periods, 'name'))->toBe(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'])
            ->and(array_unique(array_column($periods, 'status')))->toBe(['open'])
            ->and($periods[1]['end_date'])->toBe('2026-02-28');

        foreach (range(1, 11) as $i) {
            expect(CarbonImmutable::parse($periods[$i - 1]['end_date'])->addDay()->toDateString())->toBe($periods[$i]['start_date']);
        }
    });

    it('ends February on the 29th in a leap year', function () {
        createCalendar()->assertCreated();

        $periods = createYears(['start_date' => '2028-01-01'])->assertCreated()->json('data.0.periods');

        expect($periods[1]['end_date'])->toBe('2028-02-29');
    });

    it('supports a fiscal year that starts mid-calendar-year', function () {
        createCalendar(7)->assertCreated();

        $year = createYears(['start_date' => '2026-07-01'])->assertCreated()->json('data.0');

        expect($year['name'])->toBe('FY2026/2027')
            ->and($year['end_date'])->toBe('2027-06-30')
            ->and($year['periods'][0]['name'])->toBe('2026-07')
            ->and($year['periods'][11]['name'])->toBe('2027-06')
            ->and($year['periods'][11]['end_date'])->toBe('2027-06-30');
    });

    it('continues the previous year without a gap and refuses another start date', function () {
        createCalendar()->assertCreated();
        createYears()->assertCreated();

        createYears(['start_date' => '2028-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
        createYears(['start_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        $next = createYears([])->assertCreated()->json('data.0');
        expect($next['name'])->toBe('FY2027')->and($next['start_date'])->toBe('2027-01-01');

        $third = createYears(['start_date' => '2028-01-01'])->assertCreated()->json('data.0');
        expect($third['name'])->toBe('FY2028');
    });

    it('creates several years at once and refuses a name for more than one', function () {
        createCalendar()->assertCreated();

        createYears(['start_date' => '2026-01-01', 'count' => 3, 'name' => 'Custom'])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $years = createYears(['start_date' => '2026-01-01', 'count' => 3])->assertCreated()->json('data');

        expect(array_column($years, 'name'))->toBe(['FY2026', 'FY2027', 'FY2028'])
            ->and($years[1]['start_date'])->toBe('2027-01-01')
            ->and(DB::table('gl_periods')->count())->toBe(36);

        createYears(['count' => 6])->assertUnprocessable()->assertJsonValidationErrors(['count']);
    });

    it('refuses a duplicate year name', function () {
        createCalendar()->assertCreated();
        createYears(['start_date' => '2026-01-01', 'name' => 'Tahun Buku'])->assertCreated();

        createYears(['name' => 'Tahun Buku'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    });

    it('lists years with their period count and shows one with its periods', function () {
        createCalendar()->assertCreated();
        $id = createYears(['start_date' => '2026-01-01', 'count' => 2])->json('data.0.id');

        $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.period_count', 12);
        $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years/{$id}")
            ->assertOk()->assertJsonCount(12, 'data.periods');
    });

    it('is independent per company: another company can use the same dates and names', function () {
        createCalendar()->assertCreated();
        createYears()->assertCreated();

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/fiscal-calendar", ['pattern' => 'monthly', 'start_month' => 1])->assertCreated();
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/fiscal-years", ['start_date' => '2026-01-01'])->assertCreated();

        expect(DB::table('gl_periods')->count())->toBe(24);
    });
});

describe('database guarantees (ADR-0003)', function () {
    beforeEach(function () {
        createCalendar()->assertCreated();
        createYears()->assertCreated();
        $this->yearId = DB::table('gl_fiscal_years')->value('id');
    });

    function insertPeriod(string $companyId, string $yearId, string $start, string $end, int $number = 99): void
    {
        DB::table('gl_periods')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $companyId, 'fiscal_year_id' => $yearId,
            'number' => $number, 'name' => 'x'.$number, 'start_date' => $start, 'end_date' => $end,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    it('refuses overlapping periods of one company', function () {
        expect(fn () => insertPeriod($this->companyId, $this->yearId, '2026-01-15', '2026-02-10'))
            ->toThrow(QueryException::class);
    });

    it('also stops overlaps with the exclusion constraint alone, without the contiguity trigger', function () {
        DB::statement('ALTER TABLE gl_periods DISABLE TRIGGER gl_periods_contiguous');

        expect(fn () => insertPeriod($this->companyId, $this->yearId, '2026-01-15', '2026-02-10'))
            ->toThrow(QueryException::class, 'gl_periods_no_overlap');
    });

    it('refuses a period that leaves a gap', function () {
        expect(fn () => insertPeriod($this->companyId, $this->yearId, '2027-02-01', '2027-02-28'))
            ->toThrow(QueryException::class, 'must be contiguous');
    });

    it('accepts a period that continues the last one and one that precedes the first', function () {
        insertPeriod($this->companyId, $this->yearId, '2027-01-01', '2027-01-31', 98);
        insertPeriod($this->companyId, $this->yearId, '2025-12-01', '2025-12-31', 99);

        expect(DB::table('gl_periods')->count())->toBe(14);
    });

    it('stops overlapping fiscal years with the exclusion constraint', function () {
        DB::statement('ALTER TABLE gl_fiscal_years DISABLE TRIGGER gl_fiscal_years_contiguous');
        $years = DB::table('gl_fiscal_years')->first();

        expect(fn () => DB::table('gl_fiscal_years')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $this->companyId, 'calendar_id' => $years->calendar_id,
            'name' => 'Overlap', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class, 'gl_fiscal_years_no_overlap');
    });

    it('protects calendars, years and periods against hard deletes', function () {
        expect(RetentionGuard::protectedTables())->toContain('gl_fiscal_calendars', 'gl_fiscal_years', 'gl_periods')
            ->and(fn () => DB::table('gl_periods')->delete())->toThrow(QueryException::class);
    });
});

describe('periods (GL-FR-03, GL-FR-04)', function () {
    beforeEach(function () {
        createCalendar()->assertCreated();
        $this->years = createYears(['start_date' => '2026-01-01', 'count' => 2])->json('data');
        $this->january = $this->years[0]['periods'][0]['id'];
    });

    it('lists periods in date order with filters and pagination', function () {
        $list = $this->actingAs($this->admin)->getJson("{$this->url}/periods")->assertOk();

        expect($list->json('meta.total'))->toBe(24)
            ->and($list->json('data.0.name'))->toBe('2026-01')
            ->and($list->json('data.23.name'))->toBe('2027-12');

        $this->actingAs($this->admin)->getJson("{$this->url}/periods?filter[fiscal_year_id]={$this->years[1]['id']}")->assertJsonPath('meta.total', 12);
        $this->actingAs($this->admin)->getJson("{$this->url}/periods?filter[date]=2026-02-28")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '2026-02');
        $this->actingAs($this->admin)->getJson("{$this->url}/periods?filter[status]=closed")->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/periods?per_page=5&page=2")->assertJsonCount(5, 'data')->assertJsonPath('data.0.name', '2026-06');
        $this->actingAs($this->admin)->getJson("{$this->url}/periods?filter[status]=archived")->assertUnprocessable();
    });

    it('shows one period and never one of another company', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/periods/{$this->january}")
            ->assertOk()->assertJson(['data' => ['name' => '2026-01', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']]);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/periods/{$this->january}")->assertNotFound();
    });

    it('closes an open period and reopens it with a reason, both audited with the actor', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->january}/close")
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->january}/close")
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->january}/reopen")
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->january}/reopen", ['reason' => 'Late supplier invoice'])
            ->assertOk()->assertJsonPath('data.status', 'open');
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->january}/reopen", ['reason' => 'Again'])->assertStatus(409);

        $reopened = AuditEntry::where('event', 'period.reopened')->where('company_id', $this->companyId)->sole();

        expect($reopened->properties['reason'])->toBe('Late supplier invoice')
            ->and((int) $reopened->causer_id)->toBe($this->admin->id)
            ->and(AuditEntry::where('event', 'period.closed')->count())->toBe(1);
    });

    it('separates the permissions to close and to reopen', function () {
        foreach (['closer' => 'periods.close', 'reopener' => 'periods.reopen'] as $name => $permission) {
            $role = Role::create(['company_id' => $this->companyId, 'name' => $name, 'guard_name' => 'web']);
            $role->permissions()->attach(Permission::where('name', $permission)->value('id'));
        }

        $closer = memberWithRoles($this->companyId, 'closer');
        $reopener = memberWithRoles($this->companyId, 'reopener');
        $closeUrl = "{$this->url}/periods/{$this->january}/close";
        $reopenUrl = "{$this->url}/periods/{$this->january}/reopen";

        $this->actingAs($reopener)->postJson($closeUrl)->assertForbidden();
        $this->actingAs($closer)->postJson($closeUrl)->assertOk();
        $this->actingAs($closer)->postJson($reopenUrl, ['reason' => 'x'])->assertForbidden();
        $this->actingAs($reopener)->postJson($reopenUrl, ['reason' => 'Correction'])->assertOk();

        foreach (['viewer', 'preparer', 'approver'] as $role) {
            $this->actingAs(memberWithRoles($this->companyId, $role))->postJson($closeUrl)->assertForbidden();
        }
    });

    it('refuses to change periods once the company is inactive', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$this->january}/close")
            ->assertStatus(409)->assertJson(['code' => 'COMPANY_READ_ONLY']);
        $this->actingAs($this->admin)->getJson("{$this->url}/periods/{$this->january}")->assertOk();
    });
});

describe('period lookup for other modules (GL-FR-04)', function () {
    it('finds the period that contains a date, boundaries included', function () {
        createCalendar()->assertCreated();
        createYears()->assertCreated();
        $directory = app(PeriodDirectory::class);

        expect($directory->forDate($this->companyId, '2026-01-01')->name)->toBe('2026-01')
            ->and($directory->forDate($this->companyId, '2026-12-31')->name)->toBe('2026-12')
            ->and($directory->forDate($this->companyId, CarbonImmutable::parse('2026-03-15 18:30'))->name)->toBe('2026-03')
            ->and($directory->forDate($this->companyId, '2026-03-15')->isOpen())->toBeTrue()
            ->and($directory->forDate($this->companyId, '2025-12-31'))->toBeNull()
            ->and($directory->forDate($this->companyId, '2027-01-01'))->toBeNull()
            ->and($directory->forDate((string) Str::ulid(), '2026-03-15'))->toBeNull();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$directory->forDate($this->companyId, '2026-03-15')->id}/close")->assertOk();

        expect($directory->forDate($this->companyId, '2026-03-15')->isOpen())->toBeFalse();
    });

    it('reports what keeps the calendar from being ready', function () {
        $directory = app(PeriodDirectory::class);

        expect($directory->setupProblems($this->companyId))->toBe(['calendar_missing']);

        createCalendar()->assertCreated();
        expect($directory->setupProblems($this->companyId))->toBe(['no_fiscal_year']);

        createYears()->assertCreated();
        expect($directory->setupProblems($this->companyId))->toBe([]);

        Period::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get()
            ->each(fn (Period $period) => $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$period->id}/close")->assertOk());

        expect($directory->setupProblems($this->companyId))->toBe(['no_open_period']);
    });
});

describe('activation prerequisite (GL-FR-03, X-AC-03)', function () {
    it('refuses to activate without a usable calendar and says what is missing', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        $activate = fn () => $this->actingAs($this->admin)->postJson("{$this->url}/activate");

        $activate()->assertUnprocessable()->assertJson(['code' => 'COMPANY_NOT_READY'])->assertJsonValidationErrors(['fiscal_calendar']);

        createCalendar()->assertCreated();
        expect($activate()->assertUnprocessable()->json('errors.fiscal_calendar.0'))->toContain('no fiscal year');

        createYears()->assertCreated();
        $first = Period::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->orderBy('start_date')->get();
        $first->each(fn (Period $period) => $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$period->id}/close")->assertOk());
        expect($activate()->assertUnprocessable()->json('errors.fiscal_calendar.0'))->toContain('no open period');

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/{$first[0]->id}/reopen", ['reason' => 'Start of operations'])->assertOk();

        $activate()->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('reports calendar and chart of accounts problems together', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")
            ->assertUnprocessable()->assertJsonValidationErrors(['chart_of_accounts', 'fiscal_calendar']);
    });
});
