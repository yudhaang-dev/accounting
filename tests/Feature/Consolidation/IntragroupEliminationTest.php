<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\Run;
use App\Modules\Consolidation\Domain\RunElimination;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The parent lent money to the child: a receivable on the books of the parent and a payable on the books of the
// child, each naming the other company as its counterparty (GL-FR-15).
beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    [$this->childId] = createWorkspaceAs($this->admin, 'Anak');

    foreach ([$this->parentId, $this->childId] as $companyId) {
        confirmChartOfAccounts($this->admin, $companyId);
        setUpFiscalCalendar($this->admin, $companyId);
    }

    igGroup('IDR');
});

function igPath(string $path = ''): string
{
    return '/api/v1/groups/'.test()->groupId.($path === '' ? '' : "/{$path}");
}

/** A group of the parent and the child, with the group accounts of the loan and a mapping for both. */
function igGroup(string $currency): void
{
    $test = test();
    $test->groupId = $test->actingAs($test->admin)->postJson('/api/v1/groups', [
        'name' => "Grup {$currency}", 'parent_company_id' => $test->parentId, 'presentation_currency' => $currency, 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $approver = User::factory()->create();
    app(GroupGrants::class)->grant($approver->id, $test->groupId, GroupAbility::Approve);
    $memberId = $test->actingAs($test->admin)->postJson(igPath('perimeter'), [
        'company_id' => $test->childId, 'control_basis' => 'Majority of votes.', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');
    $test->actingAs($approver)->postJson(igPath("perimeter/{$memberId}/approve"))->assertOk();

    $account = fn (string $code, string $name, string $class): string => $test->actingAs($test->admin)->postJson(igPath('accounts'), ['code' => $code, 'name' => $name, 'class' => $class])->assertCreated()->json('data.id');
    $test->cash = $account('1000', 'Cash', 'asset');
    $test->loanReceivable = $account('1500', 'Intragroup receivable', 'asset');
    $test->loanPayable = $account('2500', 'Intragroup payable', 'liability');
    $test->interest = $account('4500', 'Intragroup interest', 'income');
    $test->interestExpense = $account('6500', 'Intragroup interest expense', 'expense');

    $row = fn (string $company, string $code, string $group): array => ['company_id' => $company, 'account_id' => igAccountId($company, $code), 'group_account_id' => $group];

    $test->actingAs($test->admin)->putJson(igPath('account-mappings'), ['lock_version' => 0, 'effective_date' => '2026-01-01', 'mappings' => [
        $row($test->parentId, '1112', $test->cash), $row($test->parentId, '1170', $test->loanReceivable), $row($test->parentId, '4110', $test->interest),
        $row($test->childId, '1111', $test->cash), $row($test->childId, '2130', $test->loanPayable), $row($test->childId, '6220', $test->interestExpense),
    ]])->assertOk();
}

function igAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

/** Posts a journal in which the debit line, the credit line or both name a counterparty. */
function igPost(string $companyId, string $debitCode, string $creditCode, string $amount, ?string $debitCounterparty = null, ?string $creditCounterparty = null, string $date = '2026-01-10'): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [
            new PostJournalLine(igAccountId($companyId, $debitCode), $amount, '0', counterpartyCompanyId: $debitCounterparty),
            new PostJournalLine(igAccountId($companyId, $creditCode), '0', $amount, counterpartyCompanyId: $creditCounterparty),
        ],
    ));
}

/** The loan: the parent pays out, the child receives; the child books `$received`, which is less when it differs. */
function igLoan(string $given = '1000.00', ?string $received = null): void
{
    igPost(test()->parentId, '1170', '1112', $given, debitCounterparty: test()->childId);
    igPost(test()->childId, '1111', '2130', $received ?? $given, creditCounterparty: test()->parentId);
}

function igRun(string $from = '2026-01-01', string $to = '2026-01-31')
{
    return test()->actingAs(test()->admin)->postJson(igPath('runs'), ['from' => $from, 'to' => $to]);
}

function igTolerance(string $tolerance): void
{
    $group = test()->actingAs(test()->admin)->getJson(igPath())->assertOk()->json('data');

    test()->actingAs(test()->admin)->patchJson(igPath(), ['lock_version' => $group['lock_version'], 'elimination_tolerance' => $tolerance])->assertOk();
}

function igLine(array $result, string $code): array
{
    return collect($result['lines'])->firstWhere('code', $code);
}

describe('elimination of intragroup balances (CO-FR-07)', function () {
    it('eliminates a receivable and a payable that agree, leaving nothing on the group accounts', function () {
        igLoan();

        $result = igRun()->assertCreated()->json('data');

        expect(igLine($result, '1500')['closing'])->toBe('0.00')
            ->and(igLine($result, '2500')['closing'])->toBe('0.00')
            ->and(igLine($result, '1000')['closing'])->toBe('0.00')
            ->and(igLine($result, '1500')['sources'])->toHaveCount(1)
            ->and(igLine($result, '1500')['eliminations'])->toHaveCount(1)
            ->and(igLine($result, '1500')['eliminations'][0]['closing_credit'])->toBe('1000.00')
            ->and($result['intercompany'])->toHaveCount(1)
            ->and($result['intercompany'][0]['status'])->toBe('matched')
            ->and($result['intercompany'][0]['kind'])->toBe('balance')
            ->and($result['intercompany'][0]['difference'])->toBe('0.00')
            ->and($result['intercompany'][0]['positions'])->toHaveCount(2)
            ->and($result['unassigned_eliminations'])->toBe([])
            ->and($result['totals']['eliminations']['closing'])->toBe('0.00')
            ->and($result['formal'])->toBeFalse()
            ->and($result['balanced'])->toBeTrue();
    });

    it('matches in either direction and does not mind which entity is first', function () {
        // The child lends to the parent this time: a receivable of the child, a payable of the parent.
        igPost($this->childId, '1170', '1111', '400.00', debitCounterparty: $this->parentId);
        igPost($this->parentId, '1112', '2130', '400.00', creditCounterparty: $this->childId);
        $this->actingAs($this->admin)->putJson(igPath('account-mappings'), ['lock_version' => 1, 'effective_date' => '2026-01-01', 'mappings' => [
            ['company_id' => $this->childId, 'account_id' => igAccountId($this->childId, '1170'), 'group_account_id' => $this->loanReceivable],
            ['company_id' => $this->parentId, 'account_id' => igAccountId($this->parentId, '2130'), 'group_account_id' => $this->loanPayable],
        ]])->assertOk();

        $result = igRun()->assertCreated()->json('data');

        expect($result['intercompany'])->toHaveCount(1)
            ->and($result['intercompany'][0]['status'])->toBe('matched')
            ->and(igLine($result, '1500')['closing'])->toBe('0.00')
            ->and(igLine($result, '2500')['closing'])->toBe('0.00');
    });

    it('eliminates a pair that differs by no more than the tolerance and keeps the difference in view', function () {
        igTolerance('5.00');
        igLoan('1000.00', '998.00');

        $result = igRun()->assertCreated()->json('data');
        $difference = $result['unassigned_eliminations'];

        expect($result['elimination_tolerance'])->toBe('5.00')
            ->and($result['intercompany'][0]['status'])->toBe('matched')
            ->and($result['intercompany'][0]['difference'])->toBe('2.00')
            ->and(igLine($result, '1500')['closing'])->toBe('0.00')
            ->and(igLine($result, '2500')['closing'])->toBe('0.00')
            ->and($difference)->toHaveCount(1)
            ->and($difference[0]['kind'])->toBe('difference')
            ->and($difference[0]['closing_debit'])->toBe('2.00')
            ->and($result['balanced'])->toBeTrue();
    });

    it('does not eliminate a pair that differs by more than the tolerance, and reports it as an exception', function () {
        igTolerance('1.00');
        igLoan('1000.00', '998.00');

        $result = igRun()->assertCreated()->json('data');

        expect($result['intercompany'][0]['status'])->toBe('exception')
            ->and($result['intercompany'][0]['reason'])->toBe('difference')
            ->and($result['intercompany'][0]['difference'])->toBe('2.00')
            ->and(igLine($result, '1500')['closing'])->toBe('1000.00')
            ->and(igLine($result, '2500')['closing'])->toBe('-998.00')
            ->and(igLine($result, '1500')['eliminations'])->toBe([])
            ->and($result['unassigned_eliminations'])->toBe([])
            ->and($result['balanced'])->toBeTrue();
    });

    it('never removes a difference by itself: with no tolerance any difference is an exception', function () {
        igLoan('1000.00', '999.99');

        $result = igRun()->assertCreated()->json('data');

        expect($result['elimination_tolerance'])->toBe('0.00')
            ->and($result['intercompany'][0]['status'])->toBe('exception');
    });

    it('reports a position against a company outside the perimeter as an exception', function () {
        $outsider = (string) Str::ulid();
        igPost($this->parentId, '1170', '1112', '300.00', debitCounterparty: $outsider);

        $result = igRun()->assertCreated()->json('data');

        expect($result['intercompany'])->toHaveCount(1)
            ->and($result['intercompany'][0]['status'])->toBe('exception')
            ->and($result['intercompany'][0]['reason'])->toBe('outside_perimeter')
            ->and($result['intercompany'][0]['company_b_id'])->toBe($outsider)
            ->and(igLine($result, '1500')['closing'])->toBe('300.00');
    });

    it('treats a one-sided position as a difference: the other entity booked nothing', function () {
        igPost($this->parentId, '1170', '1112', '300.00', debitCounterparty: $this->childId);

        $result = igRun()->assertCreated()->json('data');

        expect($result['intercompany'][0]['status'])->toBe('exception')
            ->and($result['intercompany'][0]['amount_a'])->not->toBe($result['intercompany'][0]['amount_b'])
            ->and($result['intercompany'][0]['difference'])->toBe('300.00');
    });

    it('leaves lines without a counterparty alone', function () {
        igPost($this->parentId, '1170', '1112', '300.00');
        igPost($this->childId, '1111', '2130', '300.00');

        $result = igRun()->assertCreated()->json('data');

        expect($result['intercompany'])->toBe([])
            ->and($result['unassigned_eliminations'])->toBe([])
            ->and(igLine($result, '1500')['closing'])->toBe('300.00');
    });
});

describe('elimination of intragroup transactions (CO-FR-07)', function () {
    it('matches the movement of income against expense between the same two entities', function () {
        // The child pays the parent 120 of interest.
        igPost($this->childId, '6220', '1111', '120.00', debitCounterparty: $this->parentId);
        igPost($this->parentId, '1112', '4110', '120.00', creditCounterparty: $this->childId);

        $result = igRun()->assertCreated()->json('data');
        $flow = collect($result['intercompany'])->firstWhere('kind', 'flow');

        expect($flow['status'])->toBe('matched')
            ->and($flow['difference'])->toBe('0.00')
            ->and(igLine($result, '4500')['closing'])->toBe('0.00')
            ->and(igLine($result, '6500')['closing'])->toBe('0.00')
            ->and(igLine($result, '4500')['debit'])->toBe('120.00')
            ->and(igLine($result, '4500')['credit'])->toBe('120.00')
            ->and($result['balanced'])->toBeTrue();
    });

    it('judges balances and transactions apart', function () {
        igLoan();
        igPost($this->childId, '6220', '1111', '120.00', debitCounterparty: $this->parentId);
        igPost($this->parentId, '1112', '4110', '100.00', creditCounterparty: $this->childId);

        $result = igRun()->assertCreated()->json('data');
        $byKind = collect($result['intercompany'])->keyBy('kind');

        expect($byKind['balance']['status'])->toBe('matched')
            ->and($byKind['flow']['status'])->toBe('exception')
            ->and($byKind['flow']['difference'])->toBe('20.00')
            ->and(igLine($result, '1500')['closing'])->toBe('0.00')
            ->and(igLine($result, '4500')['closing'])->toBe('-100.00');
    });
});

describe('what an elimination touches (CO-AC-04, CO-FR-09)', function () {
    it('lives only in the run: the ledgers of the entities are not changed', function () {
        igLoan();
        $before = Journal::withoutGlobalScopes()->count();
        $balances = DB::table('gl_journal_lines')->count();

        $result = igRun()->assertCreated()->json('data');

        expect($result['intercompany'][0]['status'])->toBe('matched')
            ->and(Journal::withoutGlobalScopes()->count())->toBe($before)
            ->and(DB::table('gl_journal_lines')->count())->toBe($balances);
    });

    it('reads the same afterwards, whatever the ledgers or the tolerance do later', function () {
        igTolerance('5.00');
        igLoan('1000.00', '998.00');
        $first = igRun()->assertCreated()->json('data');

        igTolerance('0.00');
        igPost($this->parentId, '1170', '1112', '50.00', debitCounterparty: $this->childId);
        $again = $this->actingAs($this->admin)->getJson(igPath("runs/{$first['id']}"))->assertOk()->json('data');
        $second = igRun()->assertCreated()->json('data');

        expect($again['intercompany'])->toBe($first['intercompany'])
            ->and($again['elimination_tolerance'])->toBe('5.00')
            ->and($second['elimination_tolerance'])->toBe('0.00')
            ->and($second['intercompany'][0]['status'])->toBe('exception');
    });

    it('refuses to change the stored eliminations', function () {
        igLoan();
        $id = igRun()->assertCreated()->json('data.id');

        expect(RunElimination::where('run_id', $id)->count())->toBe(2)
            ->and(fn () => DB::table('co_run_eliminations')->where('run_id', $id)->update(['debit' => '1']))->toThrow(Exception::class)
            ->and(fn () => DB::table('co_run_matches')->where('run_id', $id)->update(['status' => 'exception']))->toThrow(Exception::class);
    });

    it('lists the eliminations in the CSV export', function () {
        igTolerance('5.00');
        igLoan('1000.00', '998.00');
        $id = igRun()->assertCreated()->json('data.id');

        $csv = $this->actingAs($this->admin)->get(igPath("runs/{$id}"), ['Accept' => 'text/csv'])->assertOk()->streamedContent();

        expect($csv)->toContain('elimination,1500')->toContain('elimination_difference')->toContain('Intragroup receivable');
    });
});

describe('the tolerance of the group (CO-FR-07)', function () {
    it('starts at zero and is changed with the lock version', function () {
        $group = $this->actingAs($this->admin)->getJson(igPath())->assertOk()->json('data');

        expect($group['elimination_tolerance'])->toBe('0.000000');

        $this->actingAs($this->admin)->patchJson(igPath(), ['lock_version' => $group['lock_version'], 'elimination_tolerance' => '2.50'])
            ->assertOk()->assertJsonPath('data.elimination_tolerance', '2.500000');
        $this->actingAs($this->admin)->patchJson(igPath(), ['lock_version' => $group['lock_version'], 'elimination_tolerance' => '9'])->assertStatus(409);
    });

    it('cannot be negative', function () {
        $group = $this->actingAs($this->admin)->getJson(igPath())->assertOk()->json('data');

        $this->actingAs($this->admin)->patchJson(igPath(), ['lock_version' => $group['lock_version'], 'elimination_tolerance' => '-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['elimination_tolerance']);
    });
});

describe('intragroup positions of an entity in another currency (CO-FR-06, CO-FR-07)', function () {
    it('translates the positions before matching them, so two agreeing entities cancel out in the presentation currency', function () {
        igGroup('USD');

        $type = fn (string $code): string => $this->actingAs($this->admin)->postJson(igPath('rate-types'), ['code' => $code, 'name' => ucfirst($code)])->assertCreated()->json('data.id');
        [$closing, $average, $historical] = [$type('closing'), $type('average'), $type('historical')];
        $cta = $this->actingAs($this->admin)->postJson(igPath('accounts'), ['code' => '3900', 'name' => 'Translation difference', 'class' => 'equity'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson(igPath('translation-policy'), [
            'lock_version' => 0, 'effective_date' => '2026-01-01', 'closing_rate_type_id' => $closing, 'average_rate_type_id' => $average,
            'historical_rate_type_id' => $historical, 'difference_account_id' => $cta,
        ])->assertOk();

        foreach ([$closing => '0.0000625', $average => '0.00006'] as $typeId => $rate) {
            $this->actingAs($this->admin)->postJson(igPath('rates'), ['rate_type_id' => $typeId, 'currency' => 'IDR', 'effective_date' => '2026-01-31', 'rate' => $rate])->assertSuccessful();
        }

        // 16,000,000 rupiah on each side: 1000 dollars at the closing rate.
        igLoan('16000000.00');

        $result = igRun()->assertCreated()->json('data');
        $position = $result['intercompany'][0]['positions'][0];

        expect($result['intercompany'][0]['status'])->toBe('matched')
            ->and($result['intercompany'][0]['difference'])->toBe('0.00')
            ->and(igLine($result, '1500')['closing'])->toBe('0.00')
            ->and(igLine($result, '1500')['sources'][0]['closing_debit'])->toBe('1000.00')
            ->and(igLine($result, '2500')['closing'])->toBe('0.00')
            ->and($position['closing_debit'] ?? $position['closing_credit'])->toBe('1000.000000')
            ->and($position['translation']['rates']['closing'])->toStartWith('0.0000625')
            ->and($result['balanced'])->toBeTrue();
    });
});

it('leaves a run without intragroup positions as it was', function () {
    $result = igRun()->assertCreated()->json('data');

    expect(Run::where('group_id', $this->groupId)->count())->toBe(1)
        ->and($result['intercompany'])->toBe([])
        ->and($result['unassigned_eliminations'])->toBe([]);
});
