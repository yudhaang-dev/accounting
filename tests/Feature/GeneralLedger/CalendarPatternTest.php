<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

function patternCalendar(array $body): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/fiscal-calendar', $body);
}

function patternYears(array $body): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/fiscal-years', $body);
}

/** Length in days of each period of a created year. */
function periodLengths(array $periods): array
{
    return array_map(fn (array $period): int => CarbonImmutable::parse($period['start_date'])->diffInDays(CarbonImmutable::parse($period['end_date'])) + 1, $periods);
}

describe('the 4-4-5 calendar (GL-FR-03)', function () {
    it('has no start month: it starts on the date of its first fiscal year', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated()
            ->assertJsonPath('data.pattern', 'four_four_five')
            ->assertJsonPath('data.start_month', null);
    });

    it('refuses a start month for it and requires one for a monthly calendar', function () {
        patternCalendar(['pattern' => 'four_four_five', 'start_month' => 1])->assertUnprocessable()->assertJsonValidationErrors(['start_month']);
        patternCalendar(['pattern' => 'free', 'start_month' => 1])->assertUnprocessable()->assertJsonValidationErrors(['start_month']);
        patternCalendar(['pattern' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors(['start_month']);
    });

    it('cuts a year into twelve periods of 4, 4 and 5 weeks, from any start date', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated();

        $year = patternYears(['start_date' => '2026-01-05'])->assertCreated()->json('data.0');

        expect($year['start_date'])->toBe('2026-01-05')
            ->and($year['end_date'])->toBe('2027-01-03')
            ->and($year['name'])->toBe('FY2026/2027')
            ->and($year['period_count'])->toBe(12)
            ->and(periodLengths($year['periods']))->toBe([28, 28, 35, 28, 28, 35, 28, 28, 35, 28, 28, 35])
            ->and($year['periods'][0]['name'])->toBe('FY2026/2027-P01')
            ->and($year['periods'][0]['start_date'])->toBe('2026-01-05')
            ->and($year['periods'][11]['status'])->toBe('open');

        // Periods follow each other without a gap or an overlap.
        for ($i = 1; $i < 12; $i++) {
            expect(CarbonImmutable::parse($year['periods'][$i]['start_date'])->toDateString())
                ->toBe(CarbonImmutable::parse($year['periods'][$i - 1]['end_date'])->addDay()->toDateString());
        }
    });

    it('continues the next year the day after the previous one', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated();
        patternYears(['start_date' => '2026-01-05', 'name' => 'FY26'])->assertCreated();

        $next = patternYears(['name' => 'FY27'])->assertCreated()->json('data.0');

        expect($next['start_date'])->toBe('2027-01-04')->and($next['end_date'])->toBe('2028-01-02');

        patternYears(['start_date' => '2028-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
    });

    it('makes a 53-week year with the extra week in the last period', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated();

        $year = patternYears(['start_date' => '2026-01-05', 'extra_week' => true])->assertCreated()->json('data.0');

        expect($year['end_date'])->toBe('2027-01-10')
            ->and(periodLengths($year['periods'])[11])->toBe(42)
            ->and(array_sum(periodLengths($year['periods'])))->toBe(371);
    });

    it('allows an extra week only for one 4-4-5 year', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated();
        patternYears(['start_date' => '2026-01-05', 'count' => 2, 'extra_week' => true])->assertUnprocessable()->assertJsonValidationErrors(['extra_week']);

        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-calendar", ['pattern' => 'monthly', 'start_month' => 1])->assertConflict();
    });

    it('refuses an extra week and explicit periods on a monthly calendar', function () {
        patternCalendar(['pattern' => 'monthly', 'start_month' => 1])->assertCreated();

        patternYears(['start_date' => '2026-01-01', 'extra_week' => true])->assertUnprocessable()->assertJsonValidationErrors(['extra_week']);
        patternYears(['start_date' => '2026-01-01', 'periods' => [['start_date' => '2026-01-01', 'end_date' => '2026-12-31']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['periods']);
    });

    it('switches pattern while no fiscal year exists, dropping or requiring the start month', function () {
        patternCalendar(['pattern' => 'monthly', 'start_month' => 4])->assertCreated();

        $this->actingAs($this->admin)->patchJson("{$this->url}/fiscal-calendar", ['lock_version' => 1, 'pattern' => 'four_four_five'])
            ->assertOk()->assertJsonPath('data.pattern', 'four_four_five')->assertJsonPath('data.start_month', null);

        $this->actingAs($this->admin)->patchJson("{$this->url}/fiscal-calendar", ['lock_version' => 2, 'pattern' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_month']);

        $this->actingAs($this->admin)->patchJson("{$this->url}/fiscal-calendar", ['lock_version' => 2, 'pattern' => 'monthly', 'start_month' => 7])
            ->assertOk()->assertJsonPath('data.pattern', 'monthly')->assertJsonPath('data.start_month', 7);
    });

    it('locks the pattern once a fiscal year exists', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated();
        patternYears(['start_date' => '2026-01-05'])->assertCreated();

        $this->actingAs($this->admin)->patchJson("{$this->url}/fiscal-calendar", ['lock_version' => 1, 'pattern' => 'free'])->assertConflict();
    });

    it('keeps the database consistent about the start month', function () {
        patternCalendar(['pattern' => 'four_four_five'])->assertCreated();

        expect(fn () => DB::table('gl_fiscal_calendars')->where('company_id', $this->companyId)->update(['start_month' => 3]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('gl_fiscal_calendars')->where('company_id', $this->companyId)->update(['pattern' => 'monthly']))->toThrow(QueryException::class);
    });
});

describe('the free-range calendar (GL-FR-03)', function () {
    $threePeriods = fn (string $from = '2026-01-01'): array => [
        ['name' => 'Q1', 'start_date' => $from, 'end_date' => '2026-03-31'],
        ['start_date' => '2026-04-01', 'end_date' => '2026-09-30'],
        ['name' => 'Q4', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31'],
    ];

    it('takes the periods the user lists and the start of the year from the first of them', function () use ($threePeriods) {
        patternCalendar(['pattern' => 'free'])->assertCreated()->assertJsonPath('data.start_month', null);

        $year = patternYears(['periods' => $threePeriods()])->assertCreated()->json('data.0');

        expect($year['name'])->toBe('FY2026')
            ->and($year['start_date'])->toBe('2026-01-01')
            ->and($year['end_date'])->toBe('2026-12-31')
            ->and($year['period_count'])->toBe(3)
            ->and(array_column($year['periods'], 'name'))->toBe(['Q1', 'FY2026-P02', 'Q4'])
            ->and(array_column($year['periods'], 'number'))->toBe([1, 2, 3]);
    });

    it('needs the periods, and creates one year at a time', function () use ($threePeriods) {
        patternCalendar(['pattern' => 'free'])->assertCreated();

        patternYears(['start_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['periods']);
        patternYears(['periods' => $threePeriods(), 'count' => 2])->assertUnprocessable()->assertJsonValidationErrors(['count']);
    });

    it('refuses periods with a gap, an overlap or an end before the start, naming the period', function () {
        patternCalendar(['pattern' => 'free'])->assertCreated();

        $response = patternYears(['periods' => [
            ['start_date' => '2026-01-01', 'end_date' => '2026-03-31'],
            ['start_date' => '2026-04-02', 'end_date' => '2026-06-30'],
            ['start_date' => '2026-06-01', 'end_date' => '2026-05-31'],
        ]])->assertUnprocessable()
            ->assertJsonValidationErrors(['periods.1.start_date', 'periods.2.start_date', 'periods.2.end_date']);

        // The keys contain dots, so read them from the array instead of through a JSON path.
        expect($response->json()['errors']['periods.1.start_date'][0])->toBe('Periods must follow each other without gaps; this one must start on 2026-04-01.');

        expect(DB::table('gl_fiscal_years')->count())->toBe(0);
    });

    it('continues the next year after the previous one', function () use ($threePeriods) {
        patternCalendar(['pattern' => 'free'])->assertCreated();
        patternYears(['periods' => $threePeriods()])->assertCreated();

        patternYears(['periods' => [['start_date' => '2027-01-02', 'end_date' => '2027-12-31']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        $next = patternYears(['periods' => [['start_date' => '2027-01-01', 'end_date' => '2027-06-30'], ['start_date' => '2027-07-01', 'end_date' => '2027-12-31']]])
            ->assertCreated()->json('data.0');

        expect($next['name'])->toBe('FY2027')->and($next['period_count'])->toBe(2);
    });

    it('takes a fiscal year that spans two calendar years, named after both', function () {
        patternCalendar(['pattern' => 'free'])->assertCreated();

        $year = patternYears(['periods' => [['start_date' => '2026-07-01', 'end_date' => '2027-06-30']]])->assertCreated()->json('data.0');

        expect($year['name'])->toBe('FY2026/2027');
    });

    it('posts into free periods like into any other: by the date of the entry', function () use ($threePeriods) {
        patternCalendar(['pattern' => 'free'])->assertCreated();
        $year = patternYears(['periods' => $threePeriods()])->assertCreated()->json('data.0');

        $this->actingAs($this->admin)->getJson("{$this->url}/periods?filter[date]=2026-08-15")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $year['periods'][1]['id']);
    });
});
