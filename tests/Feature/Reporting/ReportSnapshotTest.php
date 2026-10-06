<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\Reporting\Domain\ReportSnapshot;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Published reports are stored, not recomputed on read (RP-FR-14, RP-AC-08, X-FR-03, X-AC-04).
*/
beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = snapAccountId($this->companyId, '1112');
    $this->sales = snapAccountId($this->companyId, '4110');
    $this->expense = snapAccountId($this->companyId, '6220');

    snapPost($this->companyId, '2026-02-05', $this->bank, $this->sales, '500.00');
    snapPost($this->companyId, '2026-02-20', $this->expense, $this->bank, '200.00');

    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->structure = [
        'basis' => 'movement',
        'rows' => [
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'accounts', 'sign' => 'credit', 'accounts' => [['type' => 'account', 'id' => $this->sales]]],
            ['key' => 'costs', 'label' => 'Costs', 'type' => 'accounts', 'accounts' => [['type' => 'account', 'id' => $this->expense]]],
        ],
        'columns' => [['key' => 'month', 'label' => 'Month', 'kind' => 'period']],
    ];

    $this->definition = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [
        'key' => 'income', 'name' => 'Income', 'structure' => $this->structure,
    ])->assertCreated()->json('data');
});

function snapAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function snapPost(string $companyId, string $date, string $debit, string $credit, string $amount): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: $companyId,
        accountingDate: $date,
        description: 'Test',
        source: JournalSource::Entry,
        lines: [new PostJournalLine($debit, $amount, '0'), new PostJournalLine($credit, '0', $amount)],
    ));
}

function snapPublish(array $body = [], ?User $as = null, ?string $definitionId = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(
        test()->url.'/report-definitions/'.($definitionId ?? test()->definition['id']).'/snapshots',
        ['from' => '2026-02-01', 'to' => '2026-02-28', ...$body],
    );
}

function snapValue(array $snapshot, string $row): ?string
{
    return collect($snapshot['result']['rows'])->firstWhere('key', $row)['values']['month'];
}

describe('publishing (RP-FR-14)', function () {
    it('keeps the parameters, versions, data boundary and values, and posts no journal', function () {
        $journals = Journal::withoutGlobalScope(CompanyScope::class)->count();

        $snapshot = snapPublish(['reason' => 'February close'])->assertCreated()->json('data');

        expect($snapshot)->toMatchArray([
            'definition_id' => $this->definition['id'],
            'definition_version' => 1,
            'sequence' => 1,
            'reason' => 'February close',
            'recomputed_from_id' => null,
            'mapping_versions' => ['cash_equivalents' => null],
        ])
            ->and($snapshot['parameters'])->toMatchArray(['from' => '2026-02-01', 'to' => '2026-02-28', 'account_id' => null])
            ->and($snapshot['data_boundary'])->toHaveKeys(['coverage_from', 'coverage_to', 'coverage_granularity', 'coverage_status', 'computed_at'])
            ->and($snapshot['data_boundary']['coverage_status'])->toBe('complete')
            ->and(snapValue($snapshot, 'revenue'))->toBe('500.00')
            ->and(snapValue($snapshot, 'costs'))->toBe('200.00')
            ->and(Journal::withoutGlobalScope(CompanyScope::class)->count())->toBe($journals)
            ->and(AuditEntry::where('event', 'report_snapshot.published')->count())->toBe(1);

        $this->actingAs($this->viewer)->getJson("{$this->url}/report-snapshots/{$snapshot['id']}")->assertOk()
            ->assertJsonPath('data.result.rows.0.values.month', '500.00');
        $this->actingAs($this->viewer)->getJson("{$this->url}/report-snapshots?definition_id={$this->definition['id']}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.result');
    });

    it('numbers the snapshots of a definition without gaps', function () {
        snapPublish()->assertCreated()->assertJsonPath('data.sequence', 1);
        snapPublish(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertCreated()->assertJsonPath('data.sequence', 2);

        $this->actingAs($this->viewer)->getJson("{$this->url}/report-snapshots")->assertOk()
            ->assertJsonPath('data.0.sequence', 2)->assertJsonPath('data.1.sequence', 1);
    });

    it('asks for reports.publish', function () {
        snapPublish(as: $this->viewer)->assertForbidden();
        expect(ReportSnapshot::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
    });

    it('records the account role mapping in force', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/reporting/account-role-mappings/cash_equivalents", ['lock_version' => 0, 'account_ids' => [$this->bank]])->assertOk();

        snapPublish()->assertCreated()->assertJsonPath('data.mapping_versions.cash_equivalents', 1);
    });

    it('refuses a user limited to some dimension values, who would publish a partial report', function () {
        $member = memberWithRoles($this->companyId, 'accounting_admin');
        $type = collect($this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->json('data'))->firstWhere('key', 'department')['id'];
        $value = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => 'SALES', 'name' => 'Sales'])->assertCreated()->json('data.id');
        $membership = Membership::where('user_id', $member->id)->firstOrFail();
        $this->actingAs($this->admin)->patchJson("{$this->url}/memberships/{$membership->id}", ['lock_version' => $membership->lock_version, 'data_scope' => [$value]])->assertOk();

        $id = snapPublish()->json('data.id');

        snapPublish(as: $member)->assertForbidden();
        $this->actingAs($member)->getJson("{$this->url}/report-snapshots/{$id}")->assertForbidden();
        $this->actingAs($member)->postJson("{$this->url}/report-snapshots/{$id}/recompute")->assertForbidden();
        $this->actingAs($member)->getJson("{$this->url}/report-snapshots")->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('a snapshot stays as published (RP-AC-08, X-AC-04)', function () {
    it('does not change when the definition or the data change, and cannot be changed in the database', function () {
        $first = snapPublish()->json('data');

        $changed = $this->structure;
        $changed['rows'][0]['label'] = 'Sales';
        $changed['rows'][] = ['key' => 'bank', 'label' => 'Bank', 'type' => 'accounts', 'accounts' => [['type' => 'account', 'id' => $this->bank]]];
        $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$this->definition['id']}", ['structure' => $changed, 'lock_version' => $this->definition['lock_version']])->assertOk();
        snapPost($this->companyId, '2026-02-25', $this->bank, $this->sales, '90.00');

        $read = $this->actingAs($this->viewer)->getJson("{$this->url}/report-snapshots/{$first['id']}")->assertOk()->json('data');

        expect($read['definition_version'])->toBe(1)
            ->and($read['result'])->toBe($first['result'])
            ->and(snapValue($read, 'revenue'))->toBe('500.00');

        $row = ReportSnapshot::withoutGlobalScope(CompanyScope::class)->findOrFail($first['id']);
        expect(fn () => DB::transaction(fn () => $row->update(['reason' => 'edited'])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => $row->delete()))->toThrow(QueryException::class);
    });

    it('recalculates into a new snapshot with the versions of now and keeps the old one', function () {
        $first = snapPublish()->json('data');

        $changed = $this->structure;
        $changed['rows'][0]['label'] = 'Sales';
        $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$this->definition['id']}", ['structure' => $changed, 'lock_version' => $this->definition['lock_version']])->assertOk();
        snapPost($this->companyId, '2026-02-25', $this->bank, $this->sales, '90.00');

        $second = $this->actingAs($this->admin)->postJson("{$this->url}/report-snapshots/{$first['id']}/recompute", ['reason' => 'Late invoice'])
            ->assertCreated()->json('data');

        expect($second)->toMatchArray(['sequence' => 2, 'definition_version' => 2, 'recomputed_from_id' => $first['id'], 'reason' => 'Late invoice'])
            ->and($second['parameters'])->toBe($first['parameters'])
            ->and(snapValue($second, 'revenue'))->toBe('590.00')
            ->and(collect($second['result']['rows'])->firstWhere('key', 'revenue')['label'])->toBe('Sales')
            ->and(AuditEntry::where('event', 'report_snapshot.recomputed')->count())->toBe(1);

        $this->actingAs($this->viewer)->getJson("{$this->url}/report-snapshots/{$first['id']}")->assertOk()
            ->assertJsonPath('data.definition_version', 1)->assertJsonPath('data.result.rows.0.values.month', '500.00');
    });

    it('recalculates against an earlier version of the definition when asked', function () {
        $first = snapPublish()->json('data');

        $changed = $this->structure;
        $changed['rows'][0]['label'] = 'Sales';
        $this->actingAs($this->admin)->putJson("{$this->url}/report-definitions/{$this->definition['id']}", ['structure' => $changed, 'lock_version' => $this->definition['lock_version']])->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/report-snapshots/{$first['id']}/recompute", ['version' => 1])
            ->assertCreated()->assertJsonPath('data.definition_version', 1)->assertJsonPath('data.sequence', 2);

        $this->actingAs($this->admin)->postJson("{$this->url}/report-snapshots/{$first['id']}/recompute", ['version' => 9])->assertNotFound();
    });
});
