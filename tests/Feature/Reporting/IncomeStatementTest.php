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

    $this->cash = isAccountId('1111');
    $this->bank = isAccountId('1112');
    $this->sales = isAccountId('4110');
    $this->cost = isAccountId('5110');
    $this->retained = isAccountId('3130');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');
});

function isAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function isPost(string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->companyId,
        accountingDate: $date,
        description: 'Activity',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
    ));
}

/** Revenue, cost and profit for the month and the year to date; the balance sheet accounts are in no row. */
function isDefinition(string $kind): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/report-definitions', [
        'key' => $kind, 'name' => 'Income statement', 'kind' => $kind,
        'structure' => [
            'basis' => 'movement',
            'rows' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'class', 'class' => 'income']]],
                ['key' => 'costs', 'label' => 'Costs', 'type' => 'accounts', 'accounts' => [['type' => 'class', 'class' => 'expense']]],
                ['key' => 'profit', 'label' => 'Profit', 'type' => 'subtotal', 'sign' => 'debit', 'terms' => [['row' => 'revenue', 'sign' => 1], ['row' => 'costs', 'sign' => -1]]],
            ],
            'columns' => [
                ['key' => 'month', 'label' => 'Month', 'kind' => 'period'],
                ['key' => 'ytd', 'label' => 'YTD', 'kind' => 'ytd'],
            ],
        ],
    ])->assertCreated()->json('data.id');
}

/** @return array<string, array<string, string|null>> row key => column key => value */
function isRun(string $id, string $from, string $to): array
{
    $result = test()->actingAs(test()->viewer)->getJson(test()->url."/report-definitions/{$id}/result?from={$from}&to={$to}")->assertOk()->json('data');

    return collect($result['rows'])->mapWithKeys(fn (array $row): array => [$row['key'] => $row['values']])->all();
}

describe('income statement (RP-FR-07)', function () {
    it('leaves the imported year to date balances out of the month and keeps them in the year to date (RP-AC-05)', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/opening-balance-imports", [
            'source_key' => 'legacy-2026-04',
            'source_system' => 'legacy',
            'cutover_date' => '2026-04-01',
            'account_mapping' => [
                ['source_code' => 'A-100', 'account_code' => '1111'],
                ['source_code' => 'E-300', 'account_code' => '3110'],
                ['source_code' => 'I-400', 'account_code' => '4110'],
                ['source_code' => 'X-500', 'account_code' => '5110'],
            ],
            'lines' => [
                ['source_account_code' => 'A-100', 'debit' => '12000000.00', 'credit' => '0'],
                ['source_account_code' => 'X-500', 'debit' => '1000000.00', 'credit' => '0'],
                ['source_account_code' => 'E-300', 'debit' => '0', 'credit' => '10000000.00'],
                ['source_account_code' => 'I-400', 'debit' => '0', 'credit' => '3000000.00'],
            ],
        ])->assertCreated();

        isPost('2026-04-10', $this->bank, $this->sales, '500000.00');
        isPost('2026-04-15', $this->cost, $this->cash, '100000.00');

        $id = isDefinition('income_statement');
        $rows = isRun($id, '2026-04-01', '2026-04-30');

        expect($rows['revenue'])->toBe(['month' => '500000.00', 'ytd' => '3500000.00'])
            ->and($rows['costs'])->toBe(['month' => '100000.00', 'ytd' => '1100000.00'])
            ->and($rows['profit'])->toBe(['month' => '400000.00', 'ytd' => '2400000.00'])
            ->and($rows)->not->toHaveKey('unmapped');

        // A plain report of the same rows has no reason to leave the import out.
        expect(isRun(isDefinition('custom'), '2026-04-01', '2026-04-30')['revenue']['month'])->toBe('3500000.00');
    });

    it('still shows the result of a closed year and nothing of the closing journal (RP-AC-06)', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/retained_earnings", ['lock_version' => 0, 'account_id' => $this->retained])->assertOk();

        isPost('2026-03-10', $this->bank, $this->sales, '1000000.00');
        isPost('2026-08-10', $this->cost, $this->cash, '400000.00');

        $year = (string) DB::table('gl_fiscal_years')->where('company_id', $this->companyId)->where('name', 'FY2026')->value('id');
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$year}/close")->assertCreated();

        $rows = isRun(isDefinition('income_statement'), '2026-12-01', '2026-12-31');

        expect($rows['revenue'])->toBe(['month' => '0.00', 'ytd' => '1000000.00'])
            ->and($rows['costs'])->toBe(['month' => '0.00', 'ytd' => '400000.00'])
            ->and($rows['profit'])->toBe(['month' => '0.00', 'ytd' => '600000.00']);

        // Without the kind the closing journal zeroes the year, which is why the kind matters.
        expect(isRun(isDefinition('custom'), '2026-12-01', '2026-12-31')['revenue']['ytd'])->toBe('0.00');
    });
});
