<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $year = $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years")->json('data.0');
    $planId = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Anggaran 2026', 'starts_on' => $year['start_date'], 'ends_on' => $year['end_date']])->json('data.id');
    $this->versionId = plVersion($planId, 'Anggaran awal');
    $this->planId = $planId;

    $this->branch = plDimType('branch');
    $this->jakarta = plDimValue($this->branch, 'JKT', 'Jakarta');
    $this->bandung = plDimValue($this->branch, 'BDG', 'Bandung');
});

function plVersion(string $planId, string $name): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url."/plans/{$planId}/versions", ['name' => $name])->assertCreated()->json('data.id');
}

function plDimType(string $key): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-types', ['key' => $key, 'label' => ucfirst($key)])->assertCreated()->json('data.id');
}

function plDimValue(string $typeId, string $code, string $name): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', ['dimension_type_id' => $typeId, 'code' => $code, 'name' => $name])->assertCreated()->json('data.id');
}

function plAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function plPeriodId(int $month, int $year = 2026): string
{
    return (string) Period::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)
        ->where('start_date', sprintf('%04d-%02d-01', $year, $month))->value('id');
}

/** @param  array<string, mixed>  $overrides */
function plRow(string $code, string $amount, int $month = 1, array $overrides = []): array
{
    return array_merge(['period_id' => plPeriodId($month), 'account_id' => plAccountId($code), 'amount' => $amount], $overrides);
}

function plEnter(string $versionId, array $rows)
{
    return test()->actingAs(test()->admin)->putJson(test()->url."/plan-versions/{$versionId}/lines", ['lines' => $rows]);
}

function plImport(string $versionId, string $csv)
{
    return test()->actingAs(test()->admin)->post(
        test()->url."/plan-versions/{$versionId}/lines/import",
        ['file' => UploadedFile::fake()->createWithContent('plan.csv', $csv)],
        ['Accept' => 'application/json'],
    );
}

function plLines(string $versionId)
{
    return PlanLine::withoutGlobalScope(CompanyScope::class)->where('plan_version_id', $versionId)->orderBy('period_id')->orderBy('account_id')->orderBy('dimension_set_id')->get();
}

function plPost(string $date, string $debitCode, string $creditCode, string $amount, ?array $dimensions = null): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Realisasi',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine(plAccountId($debitCode), $amount, '0', dimensions: $dimensions),
            new PostJournalLine(plAccountId($creditCode), '0', $amount, dimensions: $dimensions),
        ],
    ));
}

describe('entering lines (PL-FR-02)', function () {
    it('enters cells, replaces the amount of a cell that exists and lists them with their dimensions', function () {
        $result = plEnter($this->versionId, [
            plRow('4110', '1500000.5', 1, ['note' => 'Penjualan Januari']),
            plRow('4110', '700000', 1, ['dimensions' => ['branch' => $this->jakarta]]),
            plRow('6110', '250000', 2),
        ])->assertOk()->assertJsonPath('data.created', 3)->assertJsonPath('data.updated', 0);

        plEnter($this->versionId, [plRow('4110', '2000000', 1), plRow('6110', '300000', 3)])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);

        $lines = $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$this->versionId}/lines")->assertOk()->assertJsonCount(4, 'data');
        $withBranch = collect($lines->json('data'))->firstWhere('amount', '700000.000000');

        expect($withBranch['dimensions'])->toBe(['branch' => $this->jakarta])
            ->and(collect($lines->json('data'))->pluck('amount')->sort()->values()->all())->toBe(['250000.000000', '300000.000000', '700000.000000', '2000000.000000'])
            ->and(plLines($this->versionId)->firstWhere('note', null)->note)->toBeNull()
            ->and(AuditEntry::where('event', 'plan_version.lines_saved')->count())->toBe(2)
            ->and(PlanVersion::withoutGlobalScope(CompanyScope::class)->find($this->versionId)->last_modified_by)->toBe($this->admin->id);

        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$this->versionId}/lines?filter[period_id]=".plPeriodId(2))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$this->versionId}/lines?filter[account_id]=".plAccountId('4110'))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$this->versionId}")->assertJsonPath('data.lines_count', 4);
    });

    it('rejects a parent account, a balance sheet account, a period outside the fiscal year and an invalid dimension per row, and writes nothing (PL-AC-02)', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years", ['start_date' => '2027-01-01'])->assertCreated();
        DB::table('gl_accounts')->where('company_id', $this->companyId)->where('code', '4120')->update(['status' => 'inactive']);

        $response = plEnter($this->versionId, [
            plRow('4110', '100'),
            plRow('4100', '100'),
            plRow('1112', '100'),
            plRow('4110', '100', 1, ['period_id' => plPeriodId(1, 2027)]),
            plRow('4110', '100', 1, ['dimensions' => ['branch' => '01'.str_repeat('0', 24)]]),
            plRow('4120', '100'),
            plRow('4110', '100', 1, ['dimensions' => ['nope' => $this->jakarta]]),
        ])->assertUnprocessable();

        $response->assertJsonValidationErrors(['lines.1.account_id', 'lines.2.account_id', 'lines.3.period_id', 'lines.4.dimensions.branch', 'lines.5.account_id'])
            ->assertJsonMissingValidationErrors(['lines.0.account_id', 'lines.0.period_id']);

        expect($response->json('errors')['lines.1.account_id'][0])->toContain('4100')
            ->and($response->json('errors')['lines.2.account_id'][0])->toContain('1112')
            ->and(plLines($this->versionId))->toHaveCount(0);
    });

    it('rejects amounts that are not decimal strings, repeated cells in one request and an unknown period or account', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/plan-versions/{$this->versionId}/lines", ['lines' => [plRow('4110', '1e3'), plRow('4110', '1.1234567')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.amount', 'lines.1.amount']);

        plEnter($this->versionId, [plRow('4110', '100'), plRow('4110', '200')])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.1.account_id']);

        plEnter($this->versionId, [plRow('4110', '100', 1, ['period_id' => str_repeat('0', 26)]), plRow('4110', '100', 1, ['account_id' => str_repeat('0', 26)])])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.period_id', 'lines.1.account_id']);

        expect(plLines($this->versionId))->toHaveCount(0);
    });

    it('accepts a negative amount and keeps dimensions apart as separate cells', function () {
        plEnter($this->versionId, [
            plRow('4192', '-50'),
            plRow('4110', '10', 1, ['dimensions' => ['branch' => $this->jakarta]]),
            plRow('4110', '20', 1, ['dimensions' => ['branch' => $this->bandung]]),
            plRow('4110', '30'),
        ])->assertOk()->assertJsonPath('data.created', 4);
    });

    it('deletes a line', function () {
        plEnter($this->versionId, [plRow('4110', '100'), plRow('6110', '50')])->assertOk();
        $line = plLines($this->versionId)->first();

        $this->actingAs($this->admin)->deleteJson("{$this->url}/plan-versions/{$this->versionId}/lines/{$line->id}")->assertNoContent();
        $this->actingAs($this->admin)->deleteJson("{$this->url}/plan-versions/{$this->versionId}/lines/{$line->id}")->assertNotFound();

        expect(plLines($this->versionId))->toHaveCount(1)
            ->and(AuditEntry::where('event', 'plan_version.line_deleted')->count())->toBe(1);
    });

    it('refuses to change a version that is not a draft', function () {
        plEnter($this->versionId, [plRow('4110', '100')])->assertOk();
        $line = plLines($this->versionId)->first();
        DB::table('pl_plan_versions')->where('id', $this->versionId)->update(['status' => 'published', 'published_at' => now(), 'published_by' => $this->admin->id]);

        plEnter($this->versionId, [plRow('4110', '1')])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        $this->actingAs($this->admin)->deleteJson("{$this->url}/plan-versions/{$this->versionId}/lines/{$line->id}")->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        plImport($this->versionId, "period,account_code,amount\n2026-01-15,4110,1\n")->assertConflict();
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$this->versionId}/lines/copy", ['from_version_id' => $this->versionId])->assertConflict();

        expect((string) plLines($this->versionId)->first()->amount)->toBe('100.000000');
    });

    it('asks for plans.manage to change and plans.read to see', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        plEnter($this->versionId, [plRow('4110', '100')])->assertOk();
        $line = plLines($this->versionId)->first();
        $base = "{$this->url}/plan-versions/{$this->versionId}/lines";

        $this->actingAs($viewer)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->get("{$base}/export")->assertOk();
        $this->actingAs($viewer)->putJson($base, ['lines' => [plRow('4110', '1')]])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("{$base}/{$line->id}")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$base}/import", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$base}/copy", ['from_version_id' => $this->versionId])->assertForbidden();
    });
});

describe('CSV import and export (PL-FR-03, PL-AC-02)', function () {
    it('imports rows with dimension columns in any order and a BOM, and replaces cells that exist', function () {
        plEnter($this->versionId, [plRow('4110', '1')])->assertOk();

        $csv = "\xEF\xBB\xBFnote,Amount,dimension.branch,account_code,period\r\n"
            ."Januari,1500000.50,,4110,2026-01-31\r\n"
            .",250000,JKT,6110,2026-02-01\r\n"
            ."\r\n"
            .'"Bandung, Maret",90,BDG,4110,2026-03-15'."\r\n";

        plImport($this->versionId, $csv)->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.updated', 1);

        $lines = plLines($this->versionId);
        $january = $lines->firstWhere('period_id', plPeriodId(1));
        $march = $lines->firstWhere('period_id', plPeriodId(3));

        expect($lines)->toHaveCount(3)
            ->and((string) $january->amount)->toBe('1500000.500000')
            ->and($january->note)->toBe('Januari')
            ->and($march->note)->toBe('Bandung, Maret')
            ->and(AuditEntry::where('event', 'plan_version.lines_imported')->count())->toBe(1);

        $this->actingAs($this->admin)->getJson("{$this->url}/plan-versions/{$this->versionId}/lines?filter[period_id]=".plPeriodId(2))
            ->assertJsonPath('data.0.dimensions', ['branch' => $this->jakarta]);
    });

    it('reports every problem with its line and records nothing', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years", ['start_date' => '2027-01-01'])->assertCreated();

        $csv = "period,account_code,amount,dimension.branch\n"
            ."2026-01-10,4110,100,\n"           // line 2: valid
            ."2026-01-10,4100,100,\n"           // line 3: parent account
            ."2026-01-10,1112,100,\n"           // line 4: balance sheet account
            ."2027-01-10,4110,100,\n"           // line 5: period outside the fiscal year
            ."2025-06-10,4110,100,\n"           // line 6: no period
            ."2026-01-10,9999,100,\n"           // line 7: no account
            ."2026-01-10,4110,100,XXX\n"        // line 8: no such dimension value
            ."2026-01-10,4110,abc,\n"           // line 9: amount
            ."2026-01-10,4110,100,\n"           // line 10: repeats line 2
            .'not-a-date,4110,100,'."\n";       // line 11: no date

        $response = plImport($this->versionId, $csv)->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'rows.3.account_id', 'rows.4.account_id', 'rows.5.period_id', 'rows.6.period', 'rows.7.account_code',
            'rows.8.dimensions.branch', 'rows.9.amount', 'rows.10.account_id', 'rows.11.period',
        ])->assertJsonMissingValidationErrors(['rows.2.account_id', 'rows.2.period_id', 'rows.2.amount']);

        expect(plLines($this->versionId))->toHaveCount(0);
    });

    it('refuses a file without the required columns, with an unknown column, without rows, or without a file', function () {
        plImport($this->versionId, "period,amount\n2026-01-10,1\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        plImport($this->versionId, "period,account_code,amount,color\n2026-01-10,4110,1,red\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        plImport($this->versionId, "period,account_code,amount,dimension.region\n2026-01-10,4110,1,X\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        plImport($this->versionId, "period,account_code,amount\n\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$this->versionId}/lines/import", [])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    });

    it('accepts a text/csv body', function () {
        $this->call('POST', "{$this->url}/plan-versions/{$this->versionId}/lines/import", server: ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], content: "period,account_code,amount\n2026-01-10,4110,5\n", parameters: [])
            ->assertOk()->assertJsonPath('data.created', 1);
    });

    it('exports what it imports, so a file survives a round trip into another version', function () {
        plEnter($this->versionId, [
            plRow('4110', '1500000.5', 1, ['note' => 'Penjualan, Januari']),
            plRow('4110', '700000', 2, ['dimensions' => ['branch' => $this->jakarta]]),
            plRow('6110', '250000', 3),
        ])->assertOk();

        $export = $this->actingAs($this->admin)->get("{$this->url}/plan-versions/{$this->versionId}/lines/export")->assertOk();
        $csv = $export->streamedContent();

        expect($export->headers->get('Content-Type'))->toContain('text/csv')
            ->and($csv)->toContain('period,account_code,amount,note,dimension.branch')
            ->and($csv)->toContain('2026-01-01,4110,1500000.500000,"Penjualan, Januari"')
            ->and($csv)->toContain('2026-02-01,4110,700000.000000,,JKT');

        $other = plVersion($this->planId, 'Forecast');
        plImport($other, $csv)->assertOk()->assertJsonPath('data.created', 3);

        $project = fn ($lines) => $lines->map(fn ($line) => [$line->period_id, $line->account_id, $line->dimension_set_id, (string) $line->amount, $line->note])->all();
        expect($project(plLines($other)))->toBe($project(plLines($this->versionId)));
    });
});

describe('copying lines (PL-FR-03)', function () {
    it('copies another version of the same fiscal year, and cells that exist take the copied amount', function () {
        plEnter($this->versionId, [plRow('4110', '100', 1, ['note' => 'Asal']), plRow('6110', '50', 2, ['dimensions' => ['branch' => $this->jakarta]])])->assertOk();
        $target = plVersion($this->planId, 'Forecast Q2');
        plEnter($target, [plRow('4110', '999'), plRow('4120', '7')])->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$target}/lines/copy", ['from_version_id' => $this->versionId])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1)->assertJsonPath('data.skipped', 0);

        $lines = plLines($target);
        expect($lines)->toHaveCount(3)
            ->and((string) $lines->firstWhere('account_id', plAccountId('4110'))->amount)->toBe('100.000000')
            ->and($lines->firstWhere('account_id', plAccountId('4110'))->note)->toBe('Asal')
            ->and((string) $lines->firstWhere('account_id', plAccountId('4120'))->amount)->toBe('7.000000')
            ->and(plLines($this->versionId))->toHaveCount(2)
            ->and(AuditEntry::where('event', 'plan_version.lines_copied')->count())->toBe(1);
    });

    it('refuses to copy a version into itself', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$this->versionId}/lines/copy", ['from_version_id' => $this->versionId])
            ->assertUnprocessable()->assertJsonValidationErrors(['from_version_id']);
    });

    it('copies from a version of another plan and leaves out the periods the plan does not cover', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years", ['start_date' => '2027-01-01'])->assertCreated();
        $otherPlan = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Anggaran 2027', 'starts_on' => '2026-12-01', 'ends_on' => '2027-12-31'])->json('data.id');
        $otherVersion = plVersion($otherPlan, 'Awal');

        plEnter($otherVersion, [plRow('4110', '100', 12), plRow('4110', '200', 1, ['period_id' => plPeriodId(1, 2027)])])->assertOk();

        // The plan of 2026 covers December 2026 but not January 2027.
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$this->versionId}/lines/copy", ['from_version_id' => $otherVersion])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 1);
    });

    it('copies the realization of a period into a period of the plan, in the direction of the normal balance', function () {
        plPost('2026-01-10', '1112', '4110', '1000.00');
        plPost('2026-01-12', '1112', '4110', '400.00', ['branch' => $this->jakarta]);
        plPost('2026-01-20', '6110', '1112', '200.00');
        plPost('2026-01-22', '4110', '1112', '100.00');   // a refund: income nets to 900
        plPost('2026-02-01', '6110', '1112', '999.00');   // another period

        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$this->versionId}/lines/copy", [
            'actuals_period_id' => plPeriodId(1), 'to_period_id' => plPeriodId(4),
        ])->assertOk()->assertJsonPath('data.created', 3)->assertJsonPath('data.skipped', 0);

        $lines = plLines($this->versionId);
        $income = $lines->where('account_id', plAccountId('4110'));
        $expense = $lines->firstWhere('account_id', plAccountId('6110'));

        expect($lines)->toHaveCount(3)
            ->and($lines->pluck('period_id')->unique()->all())->toBe([plPeriodId(4)])
            ->and($income->map(fn ($line) => (string) $line->amount)->sort()->values()->all())->toBe(['400.000000', '900.000000'])
            ->and((string) $expense->amount)->toBe('200.000000');

        // The accounts of the bank and the like are balance sheet accounts and never become plan lines.
        expect($lines->where('account_id', plAccountId('1112')))->toHaveCount(0);
    });

    it('skips the realization of an account that is no longer active', function () {
        plPost('2026-01-10', '1112', '4110', '1000.00');
        plPost('2026-01-20', '6110', '1112', '200.00');
        DB::table('gl_accounts')->where('company_id', $this->companyId)->where('code', '6110')->update(['status' => 'inactive']);

        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$this->versionId}/lines/copy", [
            'actuals_period_id' => plPeriodId(1), 'to_period_id' => plPeriodId(1),
        ])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 1);
    });

    it('needs exactly one source and a target period of the fiscal year of the plan', function () {
        $url = "{$this->url}/plan-versions/{$this->versionId}/lines/copy";

        $this->actingAs($this->admin)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['from_version_id', 'actuals_period_id']);
        $this->actingAs($this->admin)->postJson($url, ['actuals_period_id' => plPeriodId(1)])->assertUnprocessable()->assertJsonValidationErrors(['to_period_id']);
        $this->actingAs($this->admin)->postJson($url, ['from_version_id' => $this->versionId, 'actuals_period_id' => plPeriodId(1), 'to_period_id' => plPeriodId(2)])
            ->assertUnprocessable();

        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years", ['start_date' => '2027-01-01'])->assertCreated();
        $this->actingAs($this->admin)->postJson($url, ['actuals_period_id' => plPeriodId(1), 'to_period_id' => plPeriodId(1, 2027)])
            ->assertUnprocessable()->assertJsonValidationErrors(['to_period_id']);
    });
});
