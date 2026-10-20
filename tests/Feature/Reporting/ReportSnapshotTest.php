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

describe('publishing when a period or year closes (RP-FR-14)', function () {
    function snapFebruary(): string
    {
        return (string) DB::table('gl_periods')->where('company_id', test()->companyId)->where('name', '2026-02')->value('id');
    }

    function snapFlag(string $flag, bool $on = true, ?string $definitionId = null, int $version = 1)
    {
        return test()->actingAs(test()->admin)->putJson(test()->url.'/report-definitions/'.($definitionId ?? test()->definition['id']), [$flag => $on, 'lock_version' => $version]);
    }

    function snapSnapshots(?string $definitionId = null): array
    {
        return test()->actingAs(test()->admin)->getJson(test()->url.'/report-snapshots?definition_id='.($definitionId ?? test()->definition['id']))->assertOk()->json('data');
    }

    it('starts off for a new definition and changes with lock_version', function () {
        expect($this->definition)->toMatchArray(['publish_on_period_close' => false, 'publish_on_year_close' => false]);

        snapFlag('publish_on_period_close')->assertOk()->assertJsonPath('data.publish_on_period_close', true)->assertJsonPath('data.publish_on_year_close', false)->assertJsonPath('data.lock_version', 2);
        snapFlag('publish_on_year_close', true, version: 1)->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('publishes the definitions that ask for it when the period closes, and no others', function () {
        $other = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", ['key' => 'plain', 'name' => 'Plain', 'structure' => $this->structure])->assertCreated()->json('data.id');
        snapFlag('publish_on_period_close')->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/close')->assertOk();

        $snapshots = snapSnapshots();

        expect($snapshots)->toHaveCount(1)
            ->and($snapshots[0]['parameters'])->toMatchArray(['from' => '2026-02-01', 'to' => '2026-02-28'])
            ->and($snapshots[0]['reason'])->toBe('Published automatically when the period was closed.')
            ->and($snapshots[0]['published_by'])->toBe($this->admin->id)
            ->and(snapValue(test()->actingAs(test()->admin)->getJson(test()->url.'/report-snapshots/'.$snapshots[0]['id'])->json('data'), 'revenue'))->toBe('500.00')
            ->and(snapSnapshots($other))->toBeEmpty();
    });

    it('does not publish on a soft close or when only the year flag is on', function () {
        snapFlag('publish_on_year_close')->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/soft-close')->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/close')->assertOk();

        expect(snapSnapshots())->toBeEmpty();
    });

    it('publishes a balance sheet at the closing date', function () {
        $sheet = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [
            'key' => 'sheet', 'name' => 'Sheet', 'kind' => 'balance_sheet',
            'structure' => [
                'basis' => 'closing',
                'rows' => [['key' => 'assets', 'label' => 'Assets', 'type' => 'accounts', 'accounts' => [['type' => 'account', 'id' => $this->bank]]]],
                'columns' => [['key' => 'at', 'label' => 'At', 'kind' => 'as_of']],
            ],
        ])->assertCreated()->json('data.id');
        snapFlag('publish_on_period_close', true, $sheet)->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/close')->assertOk();

        expect(snapSnapshots($sheet))->toHaveCount(1)
            ->and(snapSnapshots($sheet)[0]['parameters'])->toMatchArray(['from' => '2026-02-28', 'to' => '2026-02-28']);
    });

    it('publishes again when the period is closed again after a reopening', function () {
        snapFlag('publish_on_period_close')->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/close')->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/reopen', ['reason' => 'Late invoice'])->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/close')->assertOk();

        expect(array_column(snapSnapshots(), 'sequence'))->toEqualCanonicalizing([1, 2]);
    });

    it('publishes when the fiscal year closes', function () {
        mapRoleTo('retained_earnings', snapAccountId($this->companyId, '3130'));
        snapFlag('publish_on_year_close')->assertOk();
        $year = (string) DB::table('gl_fiscal_years')->where('company_id', $this->companyId)->where('name', 'FY2026')->value('id');

        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$year}/close")->assertCreated();

        $snapshots = snapSnapshots();

        expect($snapshots)->toHaveCount(1)
            ->and($snapshots[0]['parameters'])->toMatchArray(['from' => '2026-01-01', 'to' => '2026-12-31'])
            ->and($snapshots[0]['reason'])->toBe('Published automatically when the fiscal year was closed.');

        // A year that is closed already answers from its run and publishes nothing more.
        $this->actingAs($this->admin)->postJson("{$this->url}/fiscal-years/{$year}/close")->assertOk();
        expect(snapSnapshots())->toHaveCount(1);
    });

    it('audits a definition that cannot be published and still closes the period', function () {
        // The direct cash flow needs the cash_equivalents role, which this company never mapped.
        $broken = $this->actingAs($this->admin)->postJson("{$this->url}/report-definitions", [
            'key' => 'cashflow', 'name' => 'Cash flow', 'kind' => 'cash_flow_indirect',
            'structure' => [
                'basis' => 'movement',
                'rows' => [['key' => 'cash', 'label' => 'Cash', 'type' => 'cash_equivalents']],
                'columns' => [['key' => 'month', 'label' => 'Month', 'kind' => 'period']],
            ],
        ])->assertCreated()->json('data.id');

        snapFlag('publish_on_period_close', true, $broken)->assertOk();
        snapFlag('publish_on_period_close')->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/periods/".snapFebruary().'/close')->assertOk()->assertJsonPath('data.status', 'closed');

        expect(snapSnapshots($broken))->toBeEmpty()
            ->and(snapSnapshots())->toHaveCount(1)
            ->and(AuditEntry::where('event', 'report_snapshot.auto_publish_failed')->where('company_id', $this->companyId)->count())->toBe(1);
    });
});
