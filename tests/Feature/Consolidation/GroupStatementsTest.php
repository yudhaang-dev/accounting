<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Support\Company\CompanyScope;

// The parent sells for 1000 in cash and lends 300 to the child, which spends 100 of it.
beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    [$this->childId] = createWorkspaceAs($this->admin, 'Anak');

    foreach ([$this->parentId, $this->childId] as $companyId) {
        confirmChartOfAccounts($this->admin, $companyId);
        setUpFiscalCalendar($this->admin, $companyId);
    }

    $this->groupId = $this->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $approver = User::factory()->create();
    app(GroupGrants::class)->grant($approver->id, $this->groupId, GroupAbility::Approve);
    $memberId = $this->actingAs($this->admin)->postJson(stPath('perimeter'), [
        'company_id' => $this->childId, 'control_basis' => 'Majority of votes.', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');
    $this->actingAs($approver)->postJson(stPath("perimeter/{$memberId}/approve"))->assertOk();

    $account = fn (string $code, string $class): string => $this->actingAs($this->admin)->postJson(stPath('accounts'), ['code' => $code, 'name' => "Account {$code}", 'class' => $class])->assertCreated()->json('data.id');
    [$cash, $receivable, $payable, $revenue, $expense] = [$account('1000', 'asset'), $account('1500', 'asset'), $account('2500', 'liability'), $account('4000', 'income'), $account('6000', 'expense')];

    $row = fn (string $company, string $code, string $group): array => ['company_id' => $company, 'account_id' => stAccountId($company, $code), 'group_account_id' => $group];
    $this->actingAs($this->admin)->putJson(stPath('account-mappings'), ['lock_version' => 0, 'effective_date' => '2026-01-01', 'mappings' => [
        $row($this->parentId, '1112', $cash), $row($this->parentId, '1170', $receivable), $row($this->parentId, '4110', $revenue),
        $row($this->childId, '1111', $cash), $row($this->childId, '2130', $payable), $row($this->childId, '6220', $expense),
    ]])->assertOk();

    stPost($this->parentId, '1112', '4110', '1000.00');
    stPost($this->parentId, '1170', '1112', '300.00', debitCounterparty: $this->childId);
    stPost($this->childId, '1111', '2130', '300.00', creditCounterparty: $this->parentId);
    stPost($this->childId, '6220', '1111', '100.00');

    $this->runId = $this->actingAs($this->admin)->postJson(stPath('runs'), ['from' => '2026-01-01', 'to' => '2026-01-31'])->assertCreated()->json('data.id');
});

function stPath(string $path): string
{
    return '/api/v1/groups/'.test()->groupId."/{$path}";
}

function stAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function stPost(string $companyId, string $debit, string $credit, string $amount, ?string $debitCounterparty = null, ?string $creditCounterparty = null): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId, accountingDate: '2026-01-10', description: 'Test', source: JournalSource::Entry,
        lines: [
            new PostJournalLine(stAccountId($companyId, $debit), $amount, '0', counterpartyCompanyId: $debitCounterparty),
            new PostJournalLine(stAccountId($companyId, $credit), '0', $amount, counterpartyCompanyId: $creditCounterparty),
        ],
    ));
}

function stStatements(): array
{
    return test()->actingAs(test()->admin)->getJson(stPath('runs/'.test()->runId.'/statements'))->assertOk()->json('data');
}

function stAmount(array $section, string $code): ?string
{
    return collect($section['lines'])->firstWhere('code', $code)['amount'] ?? null;
}

describe('group statements of a run (CO-FR-10)', function () {
    it('states the balance sheet of the group after the intragroup loan was eliminated', function () {
        $sheet = stStatements()['balance_sheet'];

        expect(stAmount($sheet['assets'], '1000'))->toBe('900.00')
            ->and(stAmount($sheet['assets'], '1500'))->toBe('0.00')
            ->and($sheet['assets']['total'])->toBe('900.00')
            ->and($sheet['liabilities']['total'])->toBe('0.00')
            ->and($sheet['current_year_earnings'])->toBe('900.00')
            ->and($sheet['total_liabilities_and_equity'])->toBe('900.00')
            ->and($sheet['difference'])->toBe('0.00')
            ->and($sheet['balanced'])->toBeTrue();
    });

    it('states the income statement from the movement of the range', function () {
        $income = stStatements()['income_statement'];

        expect(stAmount($income['income'], '4000'))->toBe('1000.00')
            ->and(stAmount($income['expenses'], '6000'))->toBe('100.00')
            ->and($income['net_result'])->toBe('900.00');
    });

    it('reports what was eliminated and adjusted', function () {
        $report = stStatements()['eliminations'];

        expect($report['pairs'])->toHaveCount(1)
            ->and($report['pairs'][0]['status'])->toBe('matched')
            ->and($report['eliminations'])->toHaveCount(2)
            ->and($report['adjustments'])->toBe([])
            ->and($report['total_eliminated']['closing'])->toBe('0.00');
    });

    it('reconciles each entity to the total of the group', function () {
        $reconciliation = stStatements()['reconciliation'];
        $byName = collect($reconciliation['entities'])->keyBy('company_id');

        expect($reconciliation['entities'])->toHaveCount(2)
            ->and($byName[$this->parentId]['accounts'])->toBe('0.00')
            ->and($byName[$this->parentId]['eliminations'])->toBe('-300.00')
            ->and($byName[$this->childId]['eliminations'])->toBe('300.00')
            ->and($reconciliation['total'])->toBe($reconciliation['run_total'])
            ->and($reconciliation['total'])->toBe('0.00');
    });

    it('counts an approved adjustment in the statements and in the reconciliation', function () {
        $approver = User::factory()->create();
        app(GroupGrants::class)->grant($approver->id, $this->groupId, GroupAbility::Approve);
        $accounts = collect($this->actingAs($this->admin)->getJson(stPath('accounts'))->assertOk()->json('data'))->keyBy('code');

        $id = $this->actingAs($this->admin)->postJson(stPath('runs/'.$this->runId.'/adjustments'), [
            'source' => 'Memo', 'reason' => 'Accrue the audit fee.',
            'lines' => [
                ['group_account_id' => $accounts['6000']['id'], 'debit' => '50.00', 'credit' => '0'],
                ['group_account_id' => $accounts['1000']['id'], 'debit' => '0', 'credit' => '50.00'],
            ],
        ])->assertCreated()->json('data.id');
        $this->actingAs($approver)->postJson(stPath("adjustments/{$id}/approve"))->assertOk();

        $statements = stStatements();

        expect(stAmount($statements['income_statement']['expenses'], '6000'))->toBe('150.00')
            ->and($statements['income_statement']['net_result'])->toBe('850.00')
            ->and($statements['balance_sheet']['balanced'])->toBeTrue()
            ->and($statements['eliminations']['adjustments'])->toHaveCount(1)
            ->and($statements['reconciliation']['total'])->toBe($statements['reconciliation']['run_total']);
    });

    it('carries the label of the run and no cash flow', function () {
        $statements = stStatements();

        expect($statements['label'])->toBe('management_rollup')
            ->and($statements['formal'])->toBeFalse()
            ->and($statements['cash_flow'])->toBeNull();
    });

    it('is downloaded as CSV', function () {
        $csv = $this->actingAs($this->admin)->get(stPath('runs/'.$this->runId.'/statements'), ['Accept' => 'text/csv'])->assertOk()->streamedContent();

        expect($csv)->toContain('balance_sheet,assets,1000')->toContain('income_statement,result,,net_result,900.00')->toContain('reconciliation,total');
    });

    it('needs a read grant on the group', function () {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson(stPath('runs/'.$this->runId.'/statements'))->assertForbidden();
    });
});
