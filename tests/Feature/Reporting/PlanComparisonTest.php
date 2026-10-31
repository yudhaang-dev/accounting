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
    $year = $this->actingAs($this->admin)->getJson("{$this->url}/fiscal-years")->json('data.0');
    $this->fiscalYearId = $year['id'];
    $this->planId = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Anggaran 2026', 'starts_on' => $year['start_date'], 'ends_on' => $year['end_date']])->json('data.id');
});

function pcAccount(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function pcPost(string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(pcAccount($debit), $amount, '0'), new PostJournalLine(pcAccount($credit), '0', $amount)],
    ));
}

/** A published version with one planned amount per account in January 2026. @param array<string, string> $amounts */
function pcVersion(string $name, array $amounts, bool $default = false): string
{
    $version = test()->actingAs(test()->admin)->postJson(test()->url.'/plans/'.test()->planId.'/versions', ['name' => $name])->assertCreated()->json('data');
    $period = (string) Period::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->orderBy('start_date')->value('id');

    test()->actingAs(test()->admin)->putJson(test()->url."/plan-versions/{$version['id']}/lines", ['lines' => array_map(
        fn (string $code, string $amount): array => ['period_id' => $period, 'account_id' => pcAccount($code), 'amount' => $amount],
        array_keys($amounts),
        array_values($amounts),
    )])->assertOk();

    $version = test()->actingAs(test()->admin)->getJson(test()->url."/plan-versions/{$version['id']}")->json('data');
    test()->actingAs(test()->admin)->postJson(test()->url."/plan-versions/{$version['id']}/publish", ['lock_version' => $version['lock_version']])->assertOk();

    if ($default) {
        test()->actingAs(test()->admin)->putJson(test()->url.'/planning-policy', ['lock_version' => 0, 'require_distinct_publisher' => false, 'default_versions' => [['fiscal_year_id' => test()->fiscalYearId, 'plan_version_id' => $version['id']]]])->assertOk();
    }

    return $version['id'];
}

function pcDefinition(): string
{
    $row = fn (string $key, string $code, string $sign): array => ['key' => $key, 'label' => $key, 'type' => 'accounts', 'sign' => $sign, 'accounts' => [['type' => 'account', 'id' => pcAccount($code)]]];

    return test()->actingAs(test()->admin)->postJson(test()->url.'/report-definitions', [
        'key' => 'budget', 'name' => 'Budget vs actual', 'kind' => 'income_statement',
        'structure' => [
            'basis' => 'movement',
            'rows' => [
                $row('revenue', '4110', 'credit'),
                $row('returns', '4191', 'debit'),
                $row('services', '4120', 'credit'),
                $row('costs', '5110', 'debit'),
                ['key' => 'profit', 'label' => 'Profit', 'type' => 'subtotal', 'sign' => 'debit', 'favorable' => 'higher', 'terms' => [['row' => 'revenue', 'sign' => 1], ['row' => 'services', 'sign' => 1], ['row' => 'returns', 'sign' => -1], ['row' => 'costs', 'sign' => -1]]],
            ],
            'columns' => [
                ['key' => 'actual', 'label' => 'Actual', 'kind' => 'period'],
                ['key' => 'plan', 'label' => 'Plan', 'kind' => 'plan'],
                ['key' => 'variance', 'label' => 'Variance', 'kind' => 'difference', 'minuend' => 'actual', 'subtrahend' => 'plan'],
            ],
        ],
    ])->assertCreated()->json('data.id');
}

/** @return array<string, mixed> */
function pcRun(string $id, string $query = ''): array
{
    return test()->actingAs(test()->viewer)->getJson(test()->url."/report-definitions/{$id}/result?from=2026-01-01&to=2026-01-31{$query}")->assertOk()->json('data');
}

/** @return array<string, array<string, mixed>> */
function pcRows(array $result): array
{
    return collect($result['rows'])->keyBy('key')->all();
}

describe('plan against actual (RP-FR-11, RP-FR-12)', function () {
    beforeEach(function () {
        pcPost('2026-01-10', '1112', '4110', '80.00');
        pcPost('2026-01-11', '4191', '1112', '80.00');
        pcPost('2026-01-12', '5110', '1111', '80.00');
        pcPost('2026-01-13', '1112', '4120', '50.00');
    });

    it('gives the variance, the percentage and the direction that fit each account (RP-AC-04)', function () {
        pcVersion('Anggaran awal', ['4110' => '100', '4191' => '100', '5110' => '100'], default: true);

        $rows = pcRows(pcRun(pcDefinition()));

        // A cost of 80 against 100, income of 80 against 100, a deduction of 80 against 100, and no plan at all.
        expect($rows['costs']['values'])->toBe(['actual' => '80.00', 'plan' => '100.00', 'variance' => '-20.00'])
            ->and($rows['costs']['variances']['variance'])->toBe(['percentage' => '-20.00', 'favorable' => true])
            ->and($rows['revenue']['variances']['variance'])->toBe(['percentage' => '-20.00', 'favorable' => false])
            ->and($rows['returns']['variances']['variance'])->toBe(['percentage' => '-20.00', 'favorable' => true])
            ->and($rows['services']['values'])->toBe(['actual' => '50.00', 'plan' => '0.00', 'variance' => '50.00'])
            ->and($rows['services']['variances']['variance'])->toBe(['percentage' => null, 'favorable' => true])
            ->and($rows['profit']['values'])->toBe(['actual' => '-30.00', 'plan' => '-100.00', 'variance' => '70.00'])
            ->and($rows['profit']['variances']['variance']['favorable'])->toBeTrue();
    });

    it('does not call a variance of zero favorable or not', function () {
        pcVersion('Anggaran awal', ['4110' => '80', '5110' => '80'], default: true);

        $rows = pcRows(pcRun(pcDefinition()));

        expect($rows['costs']['variances']['variance'])->toBe(['percentage' => '0.00', 'favorable' => null]);
    });

    it('reads the version that the report asks for before the default one', function () {
        pcVersion('Anggaran awal', ['5110' => '100'], default: true);
        $forecast = pcVersion('Forecast', ['5110' => '60']);
        $id = pcDefinition();

        $result = pcRun($id, "&plan_version_id={$forecast}");

        expect(pcRows($result)['costs']['values']['plan'])->toBe('60.00')
            ->and(pcRows($result)['costs']['variances']['variance']['favorable'])->toBeFalse()
            ->and($result['parameters']['plan_version_id'])->toBe($forecast)
            ->and($result['columns'][1])->toMatchArray(['kind' => 'plan', 'available' => true, 'plan_version_id' => $forecast]);

        expect(pcRows(pcRun($id))['costs']['values']['plan'])->toBe('100.00');
    });

    it('reads a plan that spans two fiscal years over a report range that does too (ADR-0017)', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years", ['start_date' => '2027-01-01'])->assertCreated();
        $plan = $this->actingAs($this->admin)->postJson("{$this->url}/plans", ['name' => 'Kampanye', 'starts_on' => '2026-11-01', 'ends_on' => '2027-02-28'])->assertCreated()->json('data.id');
        $version = $this->actingAs($this->admin)->postJson("{$this->url}/plans/{$plan}/versions", ['name' => 'Awal'])->json('data');
        $period = fn (string $date): string => (string) Period::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('start_date', $date)->value('id');

        $this->actingAs($this->admin)->putJson("{$this->url}/plan-versions/{$version['id']}/lines", ['lines' => [
            ['period_id' => $period('2026-12-01'), 'account_id' => pcAccount('5110'), 'amount' => '100'],
            ['period_id' => $period('2027-01-01'), 'account_id' => pcAccount('5110'), 'amount' => '40'],
        ]])->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/plan-versions/{$version['id']}/publish", ['lock_version' => $version['lock_version']])->assertOk();

        pcPost('2026-12-05', '5110', '1111', '70.00');
        pcPost('2027-01-05', '5110', '1111', '30.00');

        $result = $this->actingAs($this->viewer)->getJson("{$this->url}/report-definitions/".pcDefinition()."/result?from=2026-11-01&to=2027-02-28&plan_version_id={$version['id']}")
            ->assertOk()->json('data');

        expect(pcRows($result)['costs']['values'])->toBe(['actual' => '100.00', 'plan' => '140.00', 'variance' => '-40.00'])
            ->and($result['columns'][1])->toMatchArray(['kind' => 'plan', 'available' => true]);
    });

    it('has no plan value rather than zero when no version applies', function () {
        $result = pcRun(pcDefinition());
        $rows = pcRows($result);

        expect($result['columns'][1])->toMatchArray(['kind' => 'plan', 'available' => false, 'plan_version_id' => null])
            ->and($rows['costs']['values'])->toBe(['actual' => '80.00', 'plan' => null, 'variance' => null])
            ->and($rows['costs']['variances']['variance'])->toBe(['percentage' => null, 'favorable' => null]);
    });

    it('has no plan value for a version that is not the company\'s', function () {
        $result = pcRun(pcDefinition(), '&plan_version_id='.str_repeat('0', 26));

        expect($result['columns'][1]['available'])->toBeFalse();
    });
});
