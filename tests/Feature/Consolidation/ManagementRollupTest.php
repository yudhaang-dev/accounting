<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\Run;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    [$this->childId] = createWorkspaceAs($this->admin, 'Anak');

    foreach ([$this->parentId, $this->childId] as $companyId) {
        confirmChartOfAccounts($this->admin, $companyId);
        setUpFiscalCalendar($this->admin, $companyId);
    }

    $this->groupId = test()->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup Acme', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $this->approver = User::factory()->create();
    app(GroupGrants::class)->grant($this->approver->id, $this->groupId, GroupAbility::Approve);
    $this->childMember = test()->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter", [
        'company_id' => $this->childId, 'control_basis' => 'Majority of votes.', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');
    test()->actingAs($this->approver)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$this->childMember}/approve")->assertOk();

    $this->cash = rollGroupAccount('1000', 'Cash', 'asset');
    $this->revenue = rollGroupAccount('4000', 'Revenue', 'income');

    // Parent: a sale for cash. Child: a sale for cash and an expense paid in cash; the expense is not mapped.
    rollPost($this->parentId, '2026-01-10', '1112', '4110', '1000.00');
    rollPost($this->childId, '2026-01-12', '1111', '4110', '300.00');
    rollPost($this->childId, '2026-01-20', '6220', '1111', '50.00');

    rollMap([
        rollRow($this->parentId, '1112', $this->cash),
        rollRow($this->parentId, '4110', $this->revenue),
        rollRow($this->childId, '1111', $this->cash),
        rollRow($this->childId, '4110', $this->revenue),
    ]);
});

function rollGroupAccount(string $code, string $name, string $class): string
{
    return test()->actingAs(test()->admin)->postJson('/api/v1/groups/'.test()->groupId.'/accounts', ['code' => $code, 'name' => $name, 'class' => $class])->assertCreated()->json('data.id');
}

function rollAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function rollPost(string $companyId, string $date, string $debitCode, string $creditCode, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine(rollAccountId($companyId, $debitCode), $amount, '0'), new PostJournalLine(rollAccountId($companyId, $creditCode), '0', $amount)],
    ));
}

function rollRow(string $companyId, string $code, string $groupAccountId): array
{
    return ['company_id' => $companyId, 'account_id' => rollAccountId($companyId, $code), 'group_account_id' => $groupAccountId];
}

function rollMap(array $rows, int $version = 0, string $effectiveDate = '2026-01-01')
{
    return test()->actingAs(test()->admin)->putJson('/api/v1/groups/'.test()->groupId.'/account-mappings', ['lock_version' => $version, 'effective_date' => $effectiveDate, 'mappings' => $rows])->assertOk();
}

function rollClose(string $companyId, string ...$months): void
{
    $periods = collect(test()->actingAs(test()->admin)->getJson("/api/v1/companies/{$companyId}/periods")->assertOk()->json('data'))
        ->keyBy(fn (array $period) => substr($period['start_date'], 0, 7));

    foreach ($months as $month) {
        test()->actingAs(test()->admin)->postJson("/api/v1/companies/{$companyId}/periods/{$periods[$month]['id']}/close")->assertOk();
    }
}

function rollRun(string $from = '2026-01-01', string $to = '2026-01-31', ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson('/api/v1/groups/'.test()->groupId.'/runs', ['from' => $from, 'to' => $to]);
}

function rollLine(array $result, string $code): array
{
    return collect($result['lines'])->firstWhere('code', $code);
}

describe('management roll-up (CO-FR-04)', function () {
    it('adds the entities up per group account, once each, and keeps the accounts without a mapping as exceptions (CO-AC-02)', function () {
        $result = rollRun()->assertCreated()->json('data');

        expect($result['label'])->toBe('management_rollup')
            ->and($result['formal'])->toBeFalse()
            ->and(array_column($result['entities'], 'company_id'))->toEqualCanonicalizing([$this->parentId, $this->childId])
            ->and($result['presentation_currency'])->toBe('IDR')
            ->and($result['mapping_version'])->toBe(1);

        // Cash: parent 1000 + child (300 - 50). Revenue: parent 1000 + child 300.
        $cash = rollLine($result, '1000');
        expect($cash['closing'])->toBe('1250.00')->and($cash['closing_debit'])->toBe('1250.00')->and($cash['closing_credit'])->toBe('0.00')
            ->and($cash['debit'])->toBe('1300.00')->and($cash['credit'])->toBe('50.00')
            ->and(array_column($cash['sources'], 'code'))->toEqualCanonicalizing(['1112', '1111'])
            ->and(array_column($cash['sources'], 'company_id'))->toEqualCanonicalizing([$this->parentId, $this->childId]);

        $revenue = rollLine($result, '4000');
        expect($revenue['closing'])->toBe('-1300.00')->and($revenue['closing_credit'])->toBe('1300.00');

        // The unmapped expense is not lost: it is an exception, and the totals still balance.
        expect($result['exceptions'])->toHaveCount(1)
            ->and($result['exceptions'][0])->toMatchArray(['company_id' => $this->childId, 'code' => '6220', 'class' => 'expense', 'closing_debit' => '50.00', 'reason' => 'unmapped'])
            ->and($result['totals']['mapped']['closing'])->toBe('-50.00')
            ->and($result['totals']['unmapped']['closing'])->toBe('50.00')
            ->and($result['totals']['all']['closing'])->toBe('0.00')
            ->and($result['balanced'])->toBeTrue();
    });

    it('can be read back as it was computed, and listed', function () {
        $created = rollRun()->assertCreated()->json('data');

        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/runs/{$created['id']}")->assertOk()->assertJsonPath('data', $created);

        $list = $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/runs")->assertOk();
        expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.id'))->toBe($created['id'])->and($list->json('data.0'))->not->toHaveKey('lines');
    });

    it('downloads the result as CSV with every group line traceable to its sources, the exceptions and the total (X-FR-02)', function () {
        $id = rollRun()->assertCreated()->json('data.id');

        $response = $this->actingAs($this->admin)->get("/api/v1/groups/{$this->groupId}/runs/{$id}", ['Accept' => 'text/csv'])->assertOk();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        $header = array_shift($rows);
        $rows = array_map(fn (array $row) => array_combine($header, $row), $rows);

        expect($response->headers->get('Content-Disposition'))->toContain('management_rollup_2026-01-01_2026-01-31.csv');

        $types = array_count_values(array_column($rows, 'type'));
        expect($types)->toBe(['source' => 4, 'line' => 2, 'exception' => 1, 'total' => 1]);

        $cash = collect($rows)->first(fn (array $row) => $row['type'] === 'line' && $row['group_account_code'] === '1000');
        expect($cash['closing_debit'])->toBe('1250.00');

        $sources = collect($rows)->where('type', 'source')->where('group_account_code', '1000');
        expect($sources->pluck('company_name')->sort()->values()->all())->toBe(['PT Anak', 'PT Induk'])
            ->and($sources->pluck('account_code')->sort()->values()->all())->toBe(['1111', '1112']);

        $exception = collect($rows)->firstWhere('type', 'exception');
        expect($exception)->toMatchArray(['group_account_code' => '', 'account_code' => '6220', 'company_name' => 'PT Anak', 'closing_debit' => '50.00']);

        $total = collect($rows)->firstWhere('type', 'total');
        expect($total['closing_debit'])->toBe($total['closing_credit']);
    });

    it('audits every run, with who ran it and what it read (CO-FR-01)', function () {
        rollRun()->assertCreated();

        $entry = AuditEntry::where('event', 'group_run.created')->firstOrFail();
        expect((int) $entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['from'])->toBe('2026-01-01')
            ->and($entry->properties['group_id'])->toBe($this->groupId);
    });

    it('leaves the ledgers of the entities alone: nothing it does is posted to them (CO-AC-04)', function () {
        $before = Journal::withoutGlobalScope(CompanyScope::class)->count();

        rollRun()->assertCreated();

        expect(Journal::withoutGlobalScope(CompanyScope::class)->count())->toBe($before);
    });

    it('takes a mapping only from the version in force on the cutoff date (P-04)', function () {
        rollMap([rollRow($this->parentId, '1112', $this->cash)], 1, '2026-06-01');

        $january = rollRun()->assertCreated()->json('data');

        // Version 2 is not in force in January: version 1 is.
        expect($january['mapping_version'])->toBe(1)->and($january['exceptions'])->toHaveCount(1);

        // Nothing in force before the first version: every balance is an exception, none is dropped.
        $this->actingAs($this->admin)->putJson("/api/v1/groups/{$this->groupId}/account-mappings", ['lock_version' => 2, 'effective_date' => '2026-01-01', 'mappings' => []])->assertOk();
        $result = rollRun()->assertCreated()->json('data');
        expect($result['lines'])->toBe([])->and($result['exceptions'])->not->toBeEmpty();
    });
});

describe('versioned runs (CO-FR-09, CO-AC-05)', function () {
    it('keeps an earlier run as it was when the mapping changes, and records the version each used', function () {
        $first = rollRun()->assertCreated()->json('data');

        rollMap([
            rollRow($this->parentId, '1112', $this->cash),
            rollRow($this->parentId, '4110', $this->revenue),
            rollRow($this->childId, '1111', $this->cash),
            rollRow($this->childId, '4110', $this->revenue),
            rollRow($this->childId, '6220', rollGroupAccount('5000', 'Expenses', 'expense')),
        ], 1);

        $second = rollRun()->assertCreated()->json('data');

        expect($second['id'])->not->toBe($first['id'])
            ->and($second['mapping_version'])->toBe(2)
            ->and($second['exceptions'])->toBe([])
            ->and(rollLine($second, '5000')['closing_debit'])->toBe('50.00');

        $again = $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/runs/{$first['id']}")->assertOk()->json('data');
        expect($again)->toBe($first)->and($again['mapping_version'])->toBe(1)->and($again['exceptions'])->toHaveCount(1);
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/runs")->assertJsonCount(2, 'data');
    });

    it('records the perimeter and the state of every package with the run', function () {
        $run = rollRun()->assertCreated()->json('data');

        expect(array_column($run['perimeter'], 'company_id'))->toEqualCanonicalizing([$this->parentId, $this->childId])
            ->and(collect($run['perimeter'])->firstWhere('company_id', $this->childId)['approved_by'])->toBe($this->approver->id)
            ->and($run['entities'][0])->toHaveKeys(['book_status', 'coverage_status', 'coverage_granularity', 'balanced', 'source']);
    });

    it('cannot be changed or deleted afterwards', function () {
        $id = rollRun()->assertCreated()->json('data.id');

        $attempt = fn (callable $statement) => fn () => DB::transaction($statement);

        expect($attempt(fn () => DB::table('co_runs')->where('id', $id)->update(['status' => 'complete'])))->toThrow(QueryException::class);
        expect($attempt(fn () => DB::table('co_run_balances')->where('run_id', $id)->update(['closing_debit' => 0])))->toThrow(QueryException::class);
        expect($attempt(fn () => DB::table('co_runs')->where('id', $id)->delete()))->toThrow(QueryException::class);
        expect(Run::find($id))->not->toBeNull();
    });
});

describe('package state (CO-FR-03)', function () {
    it('keeps a run over open books as a draft under the default policy, and complete once they are closed', function () {
        $draft = rollRun()->assertCreated()->json('data');

        expect($draft['status'])->toBe('draft')->and(array_unique(array_column($draft['entities'], 'book_status')))->toBe(['open']);

        rollClose($this->parentId, '2026-01');
        rollClose($this->childId, '2026-01');

        expect(rollRun()->assertCreated()->json('data.status'))->toBe('complete');
    });

    it('refuses a run over open books under the policy reject, naming the entities', function () {
        $group = $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}")->json('data');
        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$this->groupId}", ['lock_version' => $group['lock_version'], 'package_policy' => 'reject'])
            ->assertOk()->assertJsonPath('data.package_policy', 'reject');

        rollRun()->assertUnprocessable()->assertJsonValidationErrors(["entities.{$this->parentId}", "entities.{$this->childId}"]);

        rollClose($this->parentId, '2026-01');
        rollClose($this->childId, '2026-01');
        rollRun()->assertCreated()->assertJsonPath('data.status', 'complete');
        expect(Run::count())->toBe(1);
    });

    it('never shows data a ledger does not hold as zero: its package is flagged and gives no balances', function () {
        $migrated = User::factory()->create();
        [$companyId] = createWorkspaceAs($migrated, 'Migrasi');
        confirmChartOfAccounts($migrated, $companyId);
        setUpFiscalCalendar($migrated, $companyId);
        $this->actingAs($migrated)->postJson("/api/v1/companies/{$companyId}/opening-balance-imports", [
            'source_key' => 'legacy', 'cutover_date' => '2026-06-01', 'ledger_start_date' => '2026-04-01',
            'account_mapping' => [['source_code' => 'A', 'account_code' => '1111'], ['source_code' => 'E', 'account_code' => '3110']],
            'lines' => [['source_account_code' => 'A', 'debit' => '100.00', 'credit' => '0'], ['source_account_code' => 'E', 'debit' => '0', 'credit' => '100.00']],
        ])->assertCreated();

        rollJoin($migrated, $companyId);

        $result = rollRun()->assertCreated()->json('data');
        $entity = collect($result['entities'])->firstWhere('company_id', $companyId);

        expect($result['status'])->toBe('draft')->and($result['coverage_status'])->toBe('partial')
            ->and($entity['coverage_status'])->toBe('unavailable')
            ->and(collect($result['exceptions'])->where('company_id', $companyId)->count())->toBe(0);
    });
});

describe('perimeter and currencies', function () {
    it('refuses to add entities of another functional currency to a group of another presentation currency (CO-AC-03)', function () {
        $usd = $this->actingAs($this->admin)->postJson('/api/v1/groups', [
            'name' => 'Grup USD', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'USD', 'effective_from' => '2026-01-01',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$usd}/runs", ['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertUnprocessable()->assertJsonValidationErrors(["entities.{$this->parentId}"]);

        expect(Run::where('group_id', $usd)->count())->toBe(0);
    });

    it('leaves out a member whose assessment has not been approved', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Ketiga');
        confirmChartOfAccounts($other, $otherCompany);
        setUpFiscalCalendar($other, $otherCompany);
        rollPost($otherCompany, '2026-01-15', '1112', '4110', '5000.00');
        rollJoin($other, $otherCompany, approve: false);

        $result = rollRun()->assertCreated()->json('data');

        expect(array_column($result['entities'], 'company_id'))->toEqualCanonicalizing([$this->parentId, $this->childId])
            ->and(rollLine($result, '1000')['closing'])->toBe('1250.00');
    });

    it('refuses a range in which a member joined or left, as the perimeter is then not the same throughout', function () {
        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$this->childMember}/end", ['effective_to' => '2026-01-31'])->assertOk();

        rollRun('2026-01-01', '2026-01-31')->assertCreated();
        rollRun('2026-01-01', '2026-02-28')->assertUnprocessable()->assertJsonValidationErrors(["entities.{$this->childId}"]);
    });

    it('refuses a range in which no member is in the perimeter', function () {
        rollRun('2025-01-01', '2025-01-31')->assertUnprocessable()->assertJsonValidationErrors(['from']);
    });
});

describe('who may run it (CO-FR-01, CO-AC-01)', function () {
    it('is refused to a user who can open several entities but holds no grant on the group', function () {
        $accountant = memberWithRoles($this->parentId, 'accounting_admin');
        Membership::factory()->create([
            'workspace_id' => Workspace::where('company_id', $this->childId)->firstOrFail()->id,
            'user_id' => $accountant->id,
        ]);
        Role::where('company_id', $this->childId)->where('name', 'accounting_admin')->firstOrFail()->users()->attach($accountant->id, ['company_id' => $this->childId]);

        rollRun(as: $accountant)->assertForbidden();
        $this->actingAs($accountant)->getJson("/api/v1/groups/{$this->groupId}/runs")->assertForbidden();
        expect(Run::count())->toBe(0);
    });

    it('is allowed to a user with a run grant even without access to the companies, who then reads but cannot open the entities', function () {
        $runner = User::factory()->create();
        app(GroupGrants::class)->grant($runner->id, $this->groupId, GroupAbility::Run);

        $result = rollRun(as: $runner)->assertCreated()->json('data');

        expect($result['created_by'])->toBe($runner->id);
        // The entities themselves stay closed to them: the company routes need membership.
        $this->actingAs($runner)->getJson("/api/v1/companies/{$this->childId}/journals")->assertForbidden();
    });

    it('is only read with a read grant, and not run', function () {
        $reader = User::factory()->create();
        app(GroupGrants::class)->grant($reader->id, $this->groupId, GroupAbility::Read);
        $id = rollRun()->assertCreated()->json('data.id');

        $this->actingAs($reader)->getJson("/api/v1/groups/{$this->groupId}/runs/{$id}")->assertOk();
        rollRun(as: $reader)->assertForbidden();
    });

    it('refuses an invalid range', function () {
        rollRun('2026-02-30', '2026-03-01')->assertUnprocessable()->assertJsonValidationErrors(['from']);
        rollRun('2026-03-01', '2026-02-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    });
});

/**
 * Puts another company into the group, with the consent of that company's user and, unless told not to, the
 * approval of the control assessment.
 */
function rollJoin(User $companyUser, string $companyId, bool $approve = true): void
{
    $proposer = test()->admin;
    // The proposer must hold `consolidation.join` in that company too: its admin makes them a member.
    Membership::factory()->create([
        'workspace_id' => Workspace::where('company_id', $companyId)->firstOrFail()->id,
        'user_id' => $proposer->id,
    ]);
    Role::where('company_id', $companyId)->where('name', 'accounting_admin')->firstOrFail()->users()->attach($proposer->id, ['company_id' => $companyId]);

    $memberId = test()->actingAs($proposer)->postJson('/api/v1/groups/'.test()->groupId.'/perimeter', [
        'company_id' => $companyId, 'control_basis' => 'Majority of votes.', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    if ($approve) {
        test()->actingAs(test()->approver)->postJson('/api/v1/groups/'.test()->groupId."/perimeter/{$memberId}/approve")->assertOk();
    }
}
