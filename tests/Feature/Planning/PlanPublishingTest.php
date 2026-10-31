<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\Planning\Domain\PlanLine;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $year = $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years")->json('data.0');
    $this->fiscalYearId = $year['id'];
    $this->planId = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Anggaran 2026', 'starts_on' => $year['start_date'], 'ends_on' => $year['end_date']])->json('data.id');
});

function pbDimensionValue(): string
{
    $type = test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-types', ['key' => 'activity', 'label' => 'Kegiatan'])->assertCreated()->json('data.id');

    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', ['dimension_type_id' => $type, 'code' => 'PROMO', 'name' => 'Promosi'])->assertCreated()->json('data.id');
}

/** A draft with one line, last changed by `$actor` (the admin by default). */
function pbVersion(string $name = 'Anggaran awal', ?User $actor = null): array
{
    $actor ??= test()->admin;
    $version = test()->actingAs($actor)->postJson(test()->url.'/plans/'.test()->planId.'/versions', ['name' => $name])->assertCreated()->json('data');

    test()->actingAs($actor)->putJson(test()->url."/plan-versions/{$version['id']}/lines", ['lines' => [[
        'period_id' => (string) Period::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->orderBy('start_date')->value('id'),
        'account_id' => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', '4110')->value('id'),
        'amount' => '1000',
    ]]])->assertOk();

    return test()->actingAs($actor)->getJson(test()->url."/plan-versions/{$version['id']}")->json('data');
}

function pbPublish(array $version, ?User $actor = null, ?int $lock = null)
{
    return test()->actingAs($actor ?? test()->admin)->postJson(test()->url."/plan-versions/{$version['id']}/publish", ['lock_version' => $lock ?? $version['lock_version']]);
}

function pbPolicy(array $body)
{
    return test()->actingAs(test()->admin)->putJson(test()->url.'/planning-policy', $body);
}

describe('publishing (PL-FR-04)', function () {
    it('publishes a draft, records the publisher and freezes its lines', function () {
        $version = pbVersion();

        $published = pbPublish($version)->assertOk()->json('data');

        expect($published)->toMatchArray(['status' => 'published', 'published_by' => $this->admin->id, 'lock_version' => $version['lock_version'] + 1])
            ->and($published['published_at'])->not->toBeNull()
            ->and(AuditEntry::where('event', 'plan_version.published')->count())->toBe(1);

        $line = PlanLine::withoutGlobalScope(CompanyScope::class)->where('plan_version_id', $version['id'])->firstOrFail();
        expect(fn () => DB::transaction(fn () => $line->update(['amount' => '1'])))->toThrow(QueryException::class);

        $this->actingAs($this->admin)->putJson("{$this->url}/plan-versions/{$version['id']}/lines", ['lines' => [[
            'period_id' => $line->period_id, 'account_id' => $line->account_id, 'amount' => '5',
        ]]])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('refuses to publish twice, an empty version and a stale lock version', function () {
        $version = pbVersion();

        pbPublish($version, lock: $version['lock_version'] + 5)->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        pbPublish($version)->assertOk();
        pbPublish($version, lock: $version['lock_version'] + 1)->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $empty = $this->actingAs($this->admin)->postJson("{$this->url}/plans/{$this->planId}/versions", ['name' => 'Kosong'])->json('data');
        pbPublish($empty)->assertUnprocessable()->assertJsonValidationErrors(['version']);
    });

    it('asks for plans.publish: a user who may only manage plans cannot publish (PL-AC-03)', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $version = pbVersion();

        pbPublish($version, $viewer)->assertForbidden();
        $this->actingAs($viewer)->putJson("{$this->url}/planning-policy", ['lock_version' => 0, 'require_distinct_publisher' => true])->assertForbidden();
        expect($this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$version['id']}")->json('data.status'))->toBe('draft');
    });

    it('lets the same user change and publish until the policy asks for a different publisher (PL-AC-03)', function () {
        $own = pbVersion('Milik admin');
        pbPublish($own)->assertOk();

        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => true])->assertOk()->assertJsonPath('data.require_distinct_publisher', true)->assertJsonPath('data.lock_version', 1);

        $mine = pbVersion('Versi saya');
        pbPublish($mine)->assertForbidden()->assertJsonPath('code', 'SEGREGATION_OF_DUTIES');

        $other = memberWithRoles($this->companyId, 'accounting_admin');
        pbPublish($mine, $other)->assertOk()->assertJsonPath('data.published_by', $other->id);

        // Whoever changes the version last is the one who cannot publish it.
        $shared = pbVersion('Dua tangan');
        $this->actingAs($other)->putJson("{$this->url}/plan-versions/{$shared['id']}/lines", ['lines' => [[
            'period_id' => PlanLine::withoutGlobalScope(CompanyScope::class)->where('plan_version_id', $shared['id'])->value('period_id'),
            'account_id' => PlanLine::withoutGlobalScope(CompanyScope::class)->where('plan_version_id', $shared['id'])->value('account_id'),
            'amount' => '2000',
        ]]])->assertOk();
        $shared = $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$shared['id']}")->json('data');

        pbPublish($shared, $other)->assertForbidden();
        pbPublish($shared)->assertOk();
    });
});

describe('planning policy (PL-FR-04)', function () {
    it('reads as off with version 0 when the company has no policy', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/planning-policy")->assertOk()
            ->assertExactJson(['data' => ['require_distinct_publisher' => false, 'default_versions' => [], 'lock_version' => 0]]);
    });

    it('sets and clears the default comparison version of a fiscal year, only for a published version of that year', function () {
        $draft = pbVersion('Draf');
        $published = pbVersion('Terbit');
        pbPublish($published)->assertOk();

        $entry = fn (?string $versionId) => ['fiscal_year_id' => $this->fiscalYearId, 'plan_version_id' => $versionId];

        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [$entry($draft['id'])]])
            ->assertUnprocessable()->assertJsonValidationErrors(['default_versions.0.plan_version_id']);
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [$entry(str_repeat('0', 26))]])
            ->assertUnprocessable()->assertJsonValidationErrors(['default_versions.0.plan_version_id']);
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [['fiscal_year_id' => str_repeat('0', 26), 'plan_version_id' => $published['id']]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['default_versions.0.fiscal_year_id']);
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [$entry($published['id']), $entry(null)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['default_versions.1.fiscal_year_id']);

        // A plan that is measured against one dimension value is no default for the whole company.
        $scoped = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Promosi', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'scope_dimension_value_id' => pbDimensionValue()])->json('data.id');
        $scopedVersion = $this->actingAs($this->admin)->postJson("{$this->url}/plans/{$scoped}/versions", ['name' => 'Awal'])->json('data');
        $this->actingAs($this->admin)->putJson("{$this->url}/plan-versions/{$scopedVersion['id']}/lines", ['lines' => [[
            'period_id' => (string) Period::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->orderBy('start_date')->value('id'),
            'account_id' => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '4110')->value('id'),
            'amount' => '10',
        ]]])->assertOk();
        pbPublish($scopedVersion)->assertOk();
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [$entry($scopedVersion['id'])]])
            ->assertUnprocessable()->assertJsonValidationErrors(['default_versions.0.plan_version_id']);

        $this->actingAs($this->admin)->getJson("{$this->url}/planning-policy")->assertJsonPath('data.default_versions', []);

        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [$entry($published['id'])]])
            ->assertOk()->assertJsonPath('data.default_versions', [['fiscal_year_id' => $this->fiscalYearId, 'plan_version_id' => $published['id']]]);

        // Leaving the list out keeps the default; null clears it.
        pbPolicy(['lock_version' => 1, 'require_distinct_publisher' => true])->assertOk()->assertJsonCount(1, 'data.default_versions');
        pbPolicy(['lock_version' => 2, 'require_distinct_publisher' => true, 'default_versions' => [$entry(null)]])->assertOk()->assertJsonCount(0, 'data.default_versions');

        expect(AuditEntry::where('event', 'planning_policy.updated')->count())->toBe(3);
    });

    it('rejects a stale lock version, including a first change from the wrong version', function () {
        pbPolicy(['lock_version' => 3, 'require_distinct_publisher' => true])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => true])->assertOk();
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => false])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('can be read by plans.read and does not leak to another company', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        pbPolicy(['lock_version' => 0, 'require_distinct_publisher' => true])->assertOk();

        $this->actingAs($viewer)->getJson("{$this->url}/planning-policy")->assertOk()->assertJsonPath('data.require_distinct_publisher', true);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Beta');
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/planning-policy")->assertOk()->assertJsonPath('data.require_distinct_publisher', false);
    });
});
