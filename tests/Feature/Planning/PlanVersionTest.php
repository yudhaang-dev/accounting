<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->fiscalYearId = $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years")->json('data.0.id');
});

function newPlan(array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/plans', array_merge([
        'name' => 'Anggaran 2026', 'fiscal_year_id' => test()->fiscalYearId, 'description' => 'Anggaran awal',
    ], $overrides));
}

function newVersion(string $planId, string $name = 'Anggaran awal')
{
    return test()->actingAs(test()->admin)->postJson(test()->url."/plans/{$planId}/versions", ['name' => $name]);
}

/** Puts a line in a draft version, the way the line entry of M7-02 will. */
function planLine(string $versionId, string $accountCode, string $amount, int $month = 1, ?string $note = null): PlanLine
{
    $companyId = test()->companyId;

    return app(CompanyContext::class)->run($companyId, fn () => PlanLine::create([
        'plan_version_id' => $versionId,
        'period_id' => Period::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->orderBy('start_date')->skip($month - 1)->firstOrFail()->id,
        'account_id' => Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $accountCode)->firstOrFail()->id,
        'dimension_set_id' => app(DimensionSets::class)->emptySet($companyId),
        'amount' => $amount,
        'note' => $note,
    ]));
}

/** Publishing arrives with M7-03; until then a version is published directly. */
function publishVersion(string $versionId): void
{
    DB::table('pl_plan_versions')->where('id', $versionId)->update([
        'status' => 'published', 'published_at' => now(), 'published_by' => test()->admin->id,
    ]);
}

describe('plans (PL-FR-01)', function () {
    it('creates a plan for a fiscal year of the company and audits it', function () {
        $plan = newPlan()->assertCreated()->json('data');

        expect($plan)->toMatchArray([
            'name' => 'Anggaran 2026', 'fiscal_year_id' => $this->fiscalYearId, 'description' => 'Anggaran awal', 'lock_version' => 1,
        ])->and(AuditEntry::where('event', 'plan.created')->where('company_id', $this->companyId)->count())->toBe(1);

        $this->actingAs($this->admin)->getJson("{$this->url}/plans/{$plan['id']}")->assertOk()->assertJsonPath('data.id', $plan['id']);
        $this->actingAs($this->admin)->getJson("{$this->url}/plans?filter[fiscal_year_id]={$this->fiscalYearId}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/plans?filter[fiscal_year_id]=".str_repeat('0', 26))->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses an unknown fiscal year and a name used in the same fiscal year', function () {
        newPlan(['fiscal_year_id' => str_repeat('0', 26)])->assertUnprocessable()->assertJsonValidationErrors(['fiscal_year_id']);

        newPlan()->assertCreated();
        newPlan()->assertUnprocessable()->assertJsonValidationErrors(['name']);
    });

    it('changes the name and description with the lock version, but never the fiscal year', function () {
        $plan = newPlan()->json('data');

        $updated = $this->actingAs($this->admin)->patchJson("{$this->url}/plans/{$plan['id']}", [
            'lock_version' => 1, 'name' => 'Anggaran Final', 'description' => null, 'fiscal_year_id' => str_repeat('0', 26),
        ])->assertOk()->json('data');

        expect($updated)->toMatchArray(['name' => 'Anggaran Final', 'description' => null, 'fiscal_year_id' => $this->fiscalYearId, 'lock_version' => 2])
            ->and(AuditEntry::where('event', 'plan.updated')->count())->toBe(1);

        $this->actingAs($this->admin)->patchJson("{$this->url}/plans/{$plan['id']}", ['lock_version' => 1, 'name' => 'Lain'])->assertConflict();
    });

    it('asks for plans.manage to change and plans.read to see', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $plan = newPlan()->json('data');

        $this->actingAs($viewer)->getJson("{$this->url}/plans")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/plans", ['name' => 'X', 'fiscal_year_id' => $this->fiscalYearId])->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->url}/plans/{$plan['id']}", ['lock_version' => 1, 'name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/plans/{$plan['id']}/versions", ['name' => 'X'])->assertForbidden();
    });

    it('does not show the plans of another company', function () {
        $plan = newPlan()->json('data');
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Beta');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/plans/{$plan['id']}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/plans")->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('plan versions (PL-FR-01, PL-AC-01)', function () {
    it('adds named drafts to a plan and keeps the name unique within it', function () {
        $plan = newPlan()->json('data');

        $version = newVersion($plan['id'])->assertCreated()->json('data');

        expect($version)->toMatchArray([
            'plan_id' => $plan['id'], 'name' => 'Anggaran awal', 'status' => 'draft', 'origin_version_id' => null,
            'published_by' => null, 'published_at' => null, 'lines_count' => 0, 'lock_version' => 1,
        ])->and(AuditEntry::where('event', 'plan_version.created')->count())->toBe(1);

        newVersion($plan['id'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        newVersion($plan['id'], 'Forecast Q2')->assertCreated();

        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions?filter[plan_id]={$plan['id']}&filter[status]=draft")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions?filter[status]=published")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$version['id']}")->assertOk()->assertJsonPath('data.name', 'Anggaran awal');
    });

    it('renames a draft and refuses to rename a published version', function () {
        $plan = newPlan()->json('data');
        $version = newVersion($plan['id'])->json('data');

        $renamed = $this->actingAs($this->admin)->patchJson("{$this->url}/plan-versions/{$version['id']}", ['lock_version' => 1, 'name' => 'Anggaran revisi'])
            ->assertOk()->json('data');
        expect($renamed['name'])->toBe('Anggaran revisi')->and($renamed['lock_version'])->toBe(2);

        $this->actingAs($this->admin)->patchJson("{$this->url}/plan-versions/{$version['id']}", ['lock_version' => 1, 'name' => 'Lain'])->assertConflict();

        publishVersion($version['id']);
        $this->actingAs($this->admin)->patchJson("{$this->url}/plan-versions/{$version['id']}", ['lock_version' => 3, 'name' => 'Lain'])
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('revises a published version into a new draft with a copy of its lines and the published values stay (PL-AC-01)', function () {
        $plan = newPlan()->json('data');
        $published = newVersion($plan['id'])->json('data');
        planLine($published['id'], '4110', '1500000.5', 1, 'Penjualan Januari');
        planLine($published['id'], '4110', '1800000', 2);
        publishVersion($published['id']);

        $draft = $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$published['id']}/revise", ['name' => 'Forecast Q2'])
            ->assertCreated()->json('data');

        expect($draft)->toMatchArray([
            'plan_id' => $plan['id'], 'name' => 'Forecast Q2', 'status' => 'draft', 'origin_version_id' => $published['id'], 'lines_count' => 2,
        ])->and(AuditEntry::where('event', 'plan_version.revised')->count())->toBe(1);

        // The copy is a draft that may change, the origin never does.
        $copy = PlanLine::withoutGlobalScope(CompanyScope::class)->where('plan_version_id', $draft['id'])->orderBy('amount')->get();
        expect($copy->map(fn ($line) => (string) $line->amount)->all())->toBe(['1500000.500000', '1800000.000000'])
            ->and($copy->first()->note)->toBe('Penjualan Januari');

        $copy->first()->update(['amount' => '999']);

        $origin = PlanLine::withoutGlobalScope(CompanyScope::class)->where('plan_version_id', $published['id'])->orderBy('amount')->get();
        expect($origin->map(fn ($line) => (string) $line->amount)->all())->toBe(['1500000.500000', '1800000.000000'])
            ->and($this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$published['id']}")->json('data.status'))->toBe('published');
    });

    it('revises only a published version, and with a name not used in the plan', function () {
        $plan = newPlan()->json('data');
        $draft = newVersion($plan['id'])->json('data');

        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$draft['id']}/revise", ['name' => 'Salinan'])
            ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        publishVersion($draft['id']);
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$draft['id']}/revise", ['name' => 'Anggaran awal'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        expect(PlanVersion::withoutGlobalScope(CompanyScope::class)->where('plan_id', $plan['id'])->count())->toBe(1);

        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$draft['id']}/revise", ['name' => 'Salinan'])->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$draft['id']}/revise", ['name' => 'Salinan'])->assertUnprocessable();
    });

    it('is refused for a viewer', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $version = newVersion(newPlan()->json('data.id'))->json('data');
        publishVersion($version['id']);

        $this->actingAs($viewer)->postJson("{$this->url}/plan-versions/{$version['id']}/revise", ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->url}/plan-versions/{$version['id']}", ['lock_version' => 1, 'name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->getJson("{$this->url}/plan-versions/{$version['id']}")->assertOk();
    });

    it('is protected by the database: lines of a version that left draft cannot change, and it cannot be deleted', function () {
        $version = newVersion(newPlan()->json('data.id'))->json('data');
        $line = planLine($version['id'], '4110', '100');
        publishVersion($version['id']);

        // A refused statement aborts the test transaction, so each one runs in its own savepoint.
        $refused = fn (callable $statement) => expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);

        $refused(fn () => DB::table('pl_plan_lines')->where('id', $line->id)->update(['amount' => '1']));
        $refused(fn () => DB::table('pl_plan_lines')->where('id', $line->id)->delete());
        $refused(fn () => planLine($version['id'], '4110', '5', 3));
        $refused(fn () => DB::table('pl_plan_versions')->where('id', $version['id'])->delete());
        $refused(fn () => DB::table('pl_plan_versions')->where('id', $version['id'])->update(['status' => 'draft', 'published_at' => null]));
        $refused(fn () => DB::table('pl_plan_versions')->where('id', $version['id'])->update(['name' => 'Ganti']));

        DB::table('pl_plan_versions')->where('id', $version['id'])->update(['status' => 'archived']);
        expect(DB::table('pl_plan_versions')->where('id', $version['id'])->value('status'))->toBe('archived');
    });
});
