<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Period;
use App\Support\Company\CompanyScope;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $type = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types", ['key' => 'activity', 'label' => 'Kegiatan', 'is_hierarchical' => true])->assertCreated()->json('data.id');
    $value = fn (string $code, ?string $parent = null) => $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => $code, 'name' => $code, 'parent_id' => $parent])->assertCreated()->json('data.id');
    $this->promo = $value('PROMO');
    $this->social = $value('SOSMED', $this->promo);

    $this->planId = $this->actingAs($this->admin)->postJson("{$this->url}/plans", [
        'name' => 'Kampanye', 'objective' => 'Promosi menaikkan penjualan', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'scope_dimension_value_id' => $this->promo,
    ])->assertCreated()->json('data.id');
});

function peAccount(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function pePost(string $date, string $debit, string $credit, string $amount, ?string $valueId = null): void
{
    $dimensions = $valueId === null ? null : ['activity' => $valueId];

    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Realisasi',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(peAccount($debit), $amount, '0', dimensions: $dimensions), new PostJournalLine(peAccount($credit), '0', $amount, dimensions: $dimensions)],
    ));
}

/** A published version of the plan; `$amounts` is month number => account code => amount. @param array<int, array<string, string>> $amounts */
function peVersion(string $name, array $amounts, ?string $planId = null): string
{
    $planId ??= test()->planId;
    $version = test()->actingAs(test()->admin)->postJson(test()->url."/plans/{$planId}/versions", ['name' => $name])->assertCreated()->json('data');
    $periods = Period::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->orderBy('start_date')->pluck('id')->all();
    $lines = [];

    foreach ($amounts as $month => $byAccount) {
        foreach ($byAccount as $code => $amount) {
            $lines[] = ['period_id' => $periods[$month - 1], 'account_id' => peAccount($code), 'amount' => $amount];
        }
    }

    test()->actingAs(test()->admin)->putJson(test()->url."/plan-versions/{$version['id']}/lines", ['lines' => $lines])->assertOk();
    $version = test()->actingAs(test()->admin)->getJson(test()->url."/plan-versions/{$version['id']}")->json('data');
    test()->actingAs(test()->admin)->postJson(test()->url."/plan-versions/{$version['id']}/publish", ['lock_version' => $version['lock_version']])->assertOk();

    return $version['id'];
}

/** @return array<string, mixed> */
function peEvaluate(string $query = '', ?User $as = null): array
{
    return test()->actingAs($as ?? test()->viewer)->getJson(test()->url.'/reports/plan-evaluation?plan_id='.test()->planId.$query)->assertOk()->json('data');
}

/** @return array<string, array<string, mixed>> */
function peRows(array $result): array
{
    return collect($result['rows'])->keyBy('code')->all();
}

describe('plan evaluation (RP-FR-11, RP-FR-12, ADR-0017)', function () {
    beforeEach(function () {
        pePost('2026-01-10', '1112', '4110', '80.00', $this->social);
        pePost('2026-01-12', '5110', '1111', '30.00', $this->promo);
        pePost('2026-02-03', '5110', '1111', '20.00', $this->social);
        pePost('2026-01-15', '1112', '4110', '1000.00');                   // outside the scope of the plan
        pePost('2026-04-02', '5110', '1111', '500.00', $this->social);     // outside the period of the plan

        $this->initial = peVersion('Estimasi awal', [1 => ['4110' => '100', '5110' => '20'], 2 => ['5110' => '20']]);
        $this->revised = peVersion('Revisi', [1 => ['4110' => '90', '5110' => '30'], 2 => ['5110' => '30']]);
    });

    it('sets the initial estimate, the revision and the actual of the scope side by side', function () {
        $result = peEvaluate();
        $rows = peRows($result);

        expect($result['versions'])->toBe(['initial' => ['id' => $this->initial, 'name' => 'Estimasi awal'], 'revision' => ['id' => $this->revised, 'name' => 'Revisi']])
            ->and(array_column($result['periods'], 'name'))->toHaveCount(3)
            ->and(array_keys($rows))->toEqual(['4110', '5110']);

        $january = $rows['4110']['cells'][0];
        expect($january)->toMatchArray(['actual' => '80.00', 'initial' => '100.00', 'revision' => '90.00'])
            ->and($january['variances']['initial'])->toBe(['amount' => '-20.00', 'percentage' => '-20.00', 'favorable' => false])
            ->and($january['variances']['revision'])->toBe(['amount' => '-10.00', 'percentage' => '-11.11', 'favorable' => false]);

        // A cost above its estimate is unfavorable, equal to it neither, and below it favorable.
        expect($rows['5110']['cells'][0]['variances']['initial'])->toBe(['amount' => '10.00', 'percentage' => '50.00', 'favorable' => false])
            ->and($rows['5110']['cells'][0]['variances']['revision'])->toBe(['amount' => '0.00', 'percentage' => '0.00', 'favorable' => null])
            ->and($rows['5110']['cells'][1]['variances']['revision'])->toBe(['amount' => '-10.00', 'percentage' => '-33.33', 'favorable' => true])
            ->and($rows['5110']['total'])->toMatchArray(['actual' => '50.00', 'initial' => '40.00', 'revision' => '60.00']);
    });

    it('gives the difference of the result per period, positive when favorable, with its running total', function () {
        $periods = peEvaluate()['periods'];

        // January: income 80 and cost 30 against 100 and 20 (revision 90 and 30).
        expect($periods[0]['result'])->toBe(['actual' => '50.00', 'initial' => '80.00', 'revision' => '60.00'])
            ->and($periods[0]['variance'])->toBe(['initial' => '-30.00', 'revision' => '-10.00'])
            ->and($periods[1]['variance'])->toBe(['initial' => '0.00', 'revision' => '10.00'])
            ->and($periods[2]['variance'])->toBe(['initial' => '0.00', 'revision' => '0.00'])
            ->and(array_column($periods, 'cumulative_variance'))->toBe([
                ['initial' => '-30.00', 'revision' => '-10.00'],
                ['initial' => '-30.00', 'revision' => '0.00'],
                ['initial' => '-30.00', 'revision' => '0.00'],
            ]);
    });

    it('reads the revision the reader names instead of the last one', function () {
        $result = peEvaluate("&revision_version_id={$this->initial}");

        expect($result['versions']['revision']['id'])->toBe($this->initial)
            ->and(peRows($result)['4110']['cells'][0]['variances']['revision']['amount'])->toBe('-20.00')
            ->and(array_column($result['available_versions'], 'id'))->toBe([$this->initial, $this->revised]);

        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/plan-evaluation?plan_id={$this->planId}&revision_version_id=".str_repeat('0', 26))
            ->assertUnprocessable()->assertJsonValidationErrors(['revision_version_id']);
    });

    it('gives the same actual as the trial balance filtered by the same dimension value', function () {
        $rows = peRows(peEvaluate());
        $trial = collect($this->actingAs($this->viewer)->getJson("{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-03-31&dimensions[activity]={$this->promo}")->assertOk()->json('data.rows'))->keyBy('code');

        expect($rows['4110']['total']['actual'])->toBe(number_format((float) $trial['4110']['credit'] - (float) $trial['4110']['debit'], 2, '.', ''))
            ->and($rows['5110']['total']['actual'])->toBe(number_format((float) $trial['5110']['debit'] - (float) $trial['5110']['credit'], 2, '.', ''));
    });

    it('measures a plan without scope against the whole company', function () {
        $this->planId = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Umum', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31'])->json('data.id');
        peVersion('Awal', [1 => ['4110' => '1000']], $this->planId);

        $rows = peRows(peEvaluate());

        expect($rows['4110']['total']['actual'])->toBe('1080.00')
            ->and($rows['5110']['total']['initial'])->toBe('0.00');
    });
});

describe('a plan that is not published yet', function () {
    it('has no estimate rather than a zero one', function () {
        pePost('2026-01-10', '1112', '4110', '80.00', $this->social);

        $result = peEvaluate();

        expect($result['versions'])->toBe(['initial' => null, 'revision' => null])
            ->and(peRows($result)['4110']['cells'][0])->toBe([
                'period_id' => $result['periods'][0]['id'], 'actual' => '80.00', 'initial' => null, 'revision' => null, 'variances' => ['initial' => null, 'revision' => null],
            ])
            ->and($result['periods'][0]['variance'])->toBe(['initial' => null, 'revision' => null]);
    });

    it('refuses an unknown plan, and asks for reports.read', function () {
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/plan-evaluation?plan_id=".str_repeat('0', 26))->assertNotFound();
        $this->actingAs($this->viewer)->getJson("{$this->url}/reports/plan-evaluation")->assertUnprocessable()->assertJsonValidationErrors(['plan_id']);

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson("{$this->url}/reports/plan-evaluation?plan_id={$this->planId}")->assertForbidden();
    });
});
