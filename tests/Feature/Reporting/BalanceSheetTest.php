<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->cash = bsAccountId('1111');
    $this->bank = bsAccountId('1112');
    $this->sales = bsAccountId('4110');
    $this->cost = bsAccountId('5110');
    $this->retained = bsAccountId('3130');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    bsPost('2026-03-10', $this->bank, $this->sales, '1000000.00');
    bsPost('2026-08-10', $this->cost, $this->cash, '400000.00');
});

function bsAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function bsPost(string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
    ));
}

/** @return array<string, mixed> */
function bsStructure(int $earningsRows = 1): array
{
    $rows = [
        ['key' => 'assets', 'label' => 'Assets', 'type' => 'accounts', 'accounts' => [['type' => 'class', 'class' => 'asset']]],
        ['key' => 'liabilities', 'label' => 'Liabilities', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'liability']]],
        ['key' => 'equity', 'label' => 'Equity', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'equity']]],
    ];

    for ($i = 1; $i <= $earningsRows; $i++) {
        $rows[] = ['key' => "earnings_{$i}", 'label' => 'Current year earnings', 'type' => 'current_year_earnings', 'sign' => 'credit'];
    }

    return [
        'basis' => 'closing',
        'rows' => $rows,
        'columns' => [['key' => 'at', 'label' => 'At date', 'kind' => 'as_of']],
    ];
}

function bsDefinition(int $earningsRows = 1)
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/report-definitions', [
        'key' => 'balance_sheet', 'name' => 'Balance sheet', 'kind' => 'balance_sheet', 'structure' => bsStructure($earningsRows),
    ]);
}

/** @return array<string, mixed> */
function bsRun(string $id, string $asOf): array
{
    return test()->actingAs(test()->viewer)->getJson(test()->url."/report-definitions/{$id}/result?as_of={$asOf}")->assertOk()->json('data');
}

function bsValue(array $result, string $row): string
{
    return collect($result['rows'])->firstWhere('key', $row)['values']['at'];
}

describe('balance sheet (RP-FR-08)', function () {
    it('shows the result of an open year once and balances (RP-AC-01)', function () {
        $result = bsRun(bsDefinition()->assertCreated()->json('data.id'), '2026-12-31');

        expect(bsValue($result, 'assets'))->toBe('600000.00')
            ->and(bsValue($result, 'equity'))->toBe('0.00')
            ->and(bsValue($result, 'earnings_1'))->toBe('600000.00')
            ->and($result['unmapped_accounts'])->toBe([])
            ->and($result['balance_checks']['at'])->toBe(['assets' => '600000.00', 'liabilities_and_equity' => '600000.00', 'difference' => '0.00', 'balanced' => true]);
    });

    it('moves the result into retained earnings once the year is closed, without a second copy (RP-AC-06)', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/retained_earnings", ['lock_version' => 0, 'account_id' => $this->retained])->assertOk();
        $year = (string) DB::table('gl_fiscal_years')->where('company_id', $this->companyId)->where('name', 'FY2026')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$year}/close")->assertCreated();

        $id = bsDefinition()->assertCreated()->json('data.id');
        $result = bsRun($id, '2026-12-31');

        expect(bsValue($result, 'equity'))->toBe('600000.00')
            ->and(bsValue($result, 'earnings_1'))->toBe('0.00')
            ->and($result['balance_checks']['at']['balanced'])->toBeTrue();

        // The day before the closing journal the year still shows its result as current year earnings.
        $earlier = bsRun($id, '2026-12-30');
        expect(bsValue($earlier, 'equity'))->toBe('0.00')->and(bsValue($earlier, 'earnings_1'))->toBe('600000.00');
    });

    it('refuses a second current year earnings row', function () {
        bsDefinition(2)->assertUnprocessable()->assertJsonValidationErrors(['structure.rows.4.type']);
    });
});
