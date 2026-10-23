<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\AdjustmentLine;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    confirmChartOfAccounts($this->admin, $this->parentId);
    setUpFiscalCalendar($this->admin, $this->parentId);

    $this->groupId = $this->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $this->approver = User::factory()->create();
    app(GroupGrants::class)->grant($this->approver->id, $this->groupId, GroupAbility::Approve);

    $account = fn (string $code, string $class): string => $this->actingAs($this->admin)->postJson(adjPath('accounts'), ['code' => $code, 'name' => "Account {$code}", 'class' => $class])->assertCreated()->json('data.id');
    $this->cash = $account('1000', 'asset');
    $this->revenue = $account('4000', 'income');
    $this->expense = $account('6000', 'expense');

    $accountId = fn (string $code): string => (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->parentId)->where('code', $code)->value('id');
    $this->actingAs($this->admin)->putJson(adjPath('account-mappings'), ['lock_version' => 0, 'effective_date' => '2026-01-01', 'mappings' => [
        ['company_id' => $this->parentId, 'account_id' => $accountId('1112'), 'group_account_id' => $this->cash],
        ['company_id' => $this->parentId, 'account_id' => $accountId('4110'), 'group_account_id' => $this->revenue],
    ]])->assertOk();

    app(PostingService::class)->post(new PostJournal(
        companyId: $this->parentId, accountingDate: '2026-01-10', description: 'Sale', source: JournalSource::Entry,
        lines: [new PostJournalLine($accountId('1112'), '1000.00', '0'), new PostJournalLine($accountId('4110'), '0', '1000.00')],
    ));

    $this->runId = $this->actingAs($this->admin)->postJson(adjPath('runs'), ['from' => '2026-01-01', 'to' => '2026-01-31'])->assertCreated()->json('data.id');
});

function adjPath(string $path): string
{
    return '/api/v1/groups/'.test()->groupId."/{$path}";
}

function adjPropose(?array $lines = null, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(adjPath('runs/'.test()->runId.'/adjustments'), [
        'source' => 'Memo of the controller, 2026-02-03',
        'reason' => 'Accrue the audit fee the entities have not booked.',
        'lines' => $lines ?? [
            ['group_account_id' => test()->expense, 'debit' => '200.00', 'credit' => '0'],
            ['group_account_id' => test()->cash, 'debit' => '0', 'credit' => '200.00'],
        ],
    ]);
}

function adjResult(): array
{
    return test()->actingAs(test()->admin)->getJson(adjPath('runs/'.test()->runId))->assertOk()->json('data');
}

function adjLine(array $result, string $code): ?array
{
    return collect($result['lines'])->firstWhere('code', $code);
}

describe('proposing and approving an adjustment (CO-FR-08)', function () {
    it('records it as pending with its source, reason and lines, and changes nothing yet', function () {
        $adjustment = adjPropose()->assertCreated()->json('data');

        expect($adjustment['status'])->toBe('pending')
            ->and($adjustment['source'])->toBe('Memo of the controller, 2026-02-03')
            ->and($adjustment['lines'])->toHaveCount(2)
            ->and(adjLine(adjResult(), '6000'))->toBeNull()
            ->and(adjResult()['adjustments'])->toHaveCount(1);
    });

    it('changes the result of the run once someone else approves it', function () {
        $id = adjPropose()->assertCreated()->json('data.id');

        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');

        $result = adjResult();

        expect(adjLine($result, '6000')['closing'])->toBe('200.00')
            ->and(adjLine($result, '6000')['adjustments'])->toHaveCount(1)
            ->and(adjLine($result, '6000')['adjustments'][0]['source'])->toBe('Memo of the controller, 2026-02-03')
            ->and(adjLine($result, '1000')['closing'])->toBe('800.00')
            ->and(adjLine($result, '1000')['sources'][0]['closing'])->toBe('1000.00')
            ->and($result['totals']['adjustments']['closing'])->toBe('0.00')
            ->and($result['balanced'])->toBeTrue()
            ->and($result['formal'])->toBeFalse();
    });

    it('adds an adjustment to the movement and the closing balance, not to the opening one', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk();

        $line = adjLine(adjResult(), '6000');

        expect($line['opening_debit'])->toBe('0.00')->and($line['debit'])->toBe('200.00')->and($line['closing_debit'])->toBe('200.00');
    });

    it('is not approved by who proposed it', function () {
        app(GroupGrants::class)->grant($this->admin->id, $this->groupId, GroupAbility::Approve);
        $id = adjPropose()->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson(adjPath("adjustments/{$id}/approve"))->assertForbidden();

        expect(adjLine(adjResult(), '6000'))->toBeNull();
    });

    it('needs the approval ability to decide, and a run ability to propose', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $reader = User::factory()->create();
        app(GroupGrants::class)->grant($reader->id, $this->groupId, GroupAbility::Read);

        $this->actingAs($this->admin)->postJson(adjPath("adjustments/{$id}/approve"))->assertForbidden();
        adjPropose(as: $reader)->assertForbidden();
        $this->actingAs($reader)->getJson(adjPath('runs/'.$this->runId.'/adjustments'))->assertOk()->assertJsonCount(1, 'data');
    });

    it('can be decided only once', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk();

        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertStatus(409);
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/reject"), ['reason' => 'No.'])->assertStatus(409);
    });

    it('stays on record when rejected, with the reason, and does not count', function () {
        $id = adjPropose()->assertCreated()->json('data.id');

        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/reject"), ['reason' => 'Booked by the entity already.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Booked by the entity already.');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/reject"), [])->assertUnprocessable();

        expect(adjLine(adjResult(), '6000'))->toBeNull()->and(adjResult()['adjustments'][0]['status'])->toBe('rejected');
    });
});

describe('what an adjustment must be (CO-FR-08)', function () {
    it('balances', function () {
        adjPropose([
            ['group_account_id' => $this->expense, 'debit' => '200.00', 'credit' => '0'],
            ['group_account_id' => $this->cash, 'debit' => '0', 'credit' => '150.00'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    });

    it('puts each line on one side', function () {
        adjPropose([
            ['group_account_id' => $this->expense, 'debit' => '100.00', 'credit' => '100.00'],
            ['group_account_id' => $this->cash, 'debit' => '0', 'credit' => '0'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.debit', 'lines.1.debit']);
    });

    it('uses active accounts of this group only', function () {
        $this->actingAs($this->admin)->patchJson(adjPath("accounts/{$this->expense}"), ['lock_version' => 1, 'is_active' => false])->assertOk();

        adjPropose()->assertUnprocessable()->assertJsonValidationErrors(['lines.0.group_account_id']);
    });

    it('needs a source and a reason', function () {
        $this->actingAs($this->admin)->postJson(adjPath('runs/'.$this->runId.'/adjustments'), ['lines' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['source', 'reason', 'lines']);
    });
});

describe('where an adjustment lives (CO-AC-04, CO-FR-09, X-FR-01)', function () {
    it('never reaches a ledger of an entity', function () {
        $journals = DB::table('gl_journals')->count();
        $lines = DB::table('gl_journal_lines')->count();
        $id = adjPropose()->assertCreated()->json('data.id');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk();

        expect(DB::table('gl_journals')->count())->toBe($journals)->and(DB::table('gl_journal_lines')->count())->toBe($lines);
    });

    it('leaves the stored run alone, so a new run does not carry it', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk();

        $again = $this->actingAs($this->admin)->postJson(adjPath('runs'), ['from' => '2026-01-01', 'to' => '2026-01-31'])->assertCreated()->json('data');

        expect(adjLine($again, '6000'))->toBeNull()
            ->and($again['adjustments'])->toBe([])
            ->and(adjLine(adjResult(), '6000')['closing'])->toBe('200.00');
    });

    it('cannot change its lines afterwards', function () {
        $id = adjPropose()->assertCreated()->json('data.id');

        expect(fn () => AdjustmentLine::query()->where('adjustment_id', $id)->update(['debit' => '1']))->toThrow(Exception::class);
    });

    it('is audited at every step', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk();

        $events = AuditEntry::query()->whereIn('event', ['group_adjustment.proposed', 'group_adjustment.approved'])->pluck('event')->all();

        expect($events)->toContain('group_adjustment.proposed')->toContain('group_adjustment.approved');
    });

    it('is listed in the CSV export of the run once approved', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $this->actingAs($this->approver)->postJson(adjPath("adjustments/{$id}/approve"))->assertOk();

        $csv = $this->actingAs($this->admin)->get(adjPath('runs/'.$this->runId), ['Accept' => 'text/csv'])->assertOk()->streamedContent();

        expect($csv)->toContain('adjustment,6000');
    });

    it('is not reachable through another group', function () {
        $id = adjPropose()->assertCreated()->json('data.id');
        $other = $this->actingAs($this->admin)->postJson('/api/v1/groups', [
            'name' => 'Lain', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR', 'effective_from' => '2026-01-01',
        ])->assertCreated()->json('data.id');
        app(GroupGrants::class)->grant($this->approver->id, $other, GroupAbility::Approve);

        $this->actingAs($this->approver)->postJson("/api/v1/groups/{$other}/adjustments/{$id}/approve")->assertNotFound();
    });
});
