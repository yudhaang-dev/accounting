<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\Run;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    confirmChartOfAccounts($this->admin, $this->parentId);
    setUpFiscalCalendar($this->admin, $this->parentId);

    $this->groupId = $this->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $account = fn (string $code, string $class): string => $this->actingAs($this->admin)->postJson(fmPath('accounts'), ['code' => $code, 'name' => "Account {$code}", 'class' => $class])->assertCreated()->json('data.id');
    $this->cash = $account('1000', 'asset');
    $this->revenue = $account('4000', 'income');

    fmPost('1112', '4110', null);
    fmMap(['1112' => $this->cash, '4110' => $this->revenue]);
});

function fmPath(string $path): string
{
    return '/api/v1/groups/'.test()->groupId."/{$path}";
}

function fmAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->parentId)->where('code', $code)->value('id');
}

function fmPost(string $debit, string $credit, ?string $counterparty): void
{
    app(PostingService::class)->post(new PostJournal(
        companyId: test()->parentId, accountingDate: '2026-01-10', description: 'Test', source: JournalSource::Entry,
        lines: [new PostJournalLine(fmAccountId($debit), '1000.00', '0'), new PostJournalLine(fmAccountId($credit), '0', '1000.00', counterpartyCompanyId: $counterparty)],
    ));
}

/** @param array<string, string> $map entity account code => group account */
function fmMap(array $map): void
{
    $rows = [];

    foreach ($map as $code => $group) {
        $rows[] = ['company_id' => test()->parentId, 'account_id' => fmAccountId($code), 'group_account_id' => $group];
    }

    test()->actingAs(test()->admin)->putJson(fmPath('account-mappings'), ['lock_version' => 0, 'effective_date' => '2026-01-01', 'mappings' => $rows])->assertOk();
}

function fmCloseJanuary(): void
{
    $period = collect(test()->actingAs(test()->admin)->getJson('/api/v1/companies/'.test()->parentId.'/periods')->assertOk()->json('data'))->first(fn (array $p) => str_starts_with($p['start_date'], '2026-01'));

    test()->actingAs(test()->admin)->postJson('/api/v1/companies/'.test()->parentId."/periods/{$period['id']}/close")->assertOk();
}

function fmRun(array $extra = [])
{
    return test()->actingAs(test()->admin)->postJson(fmPath('runs'), ['from' => '2026-01-01', 'to' => '2026-01-31'] + $extra);
}

describe('formal consolidation (CO-FR-05)', function () {
    it('is a management roll-up unless a formal run is asked for', function () {
        $result = fmRun()->assertCreated()->json('data');

        expect($result['label'])->toBe('management_rollup')->and($result['formal'])->toBeFalse();
    });

    it('is formal when asked for, nothing stands in the way and every package is closed and complete', function () {
        fmCloseJanuary();

        $result = fmRun(['formal' => true])->assertCreated()->json('data');

        expect($result['label'])->toBe('consolidated')
            ->and($result['formal'])->toBeTrue()
            ->and($result['status'])->toBe('complete');
    });

    it('is a draft, and not formal, while a package is not closed', function () {
        $result = fmRun(['formal' => true])->assertCreated()->json('data');

        expect($result['label'])->toBe('consolidated')
            ->and($result['status'])->toBe('draft')
            ->and($result['formal'])->toBeFalse();
    });

    it('is refused, and not stored, while an account with a balance has no mapping', function () {
        fmPost('6220', '4110', null);

        $this->actingAs($this->admin)->postJson(fmPath('runs'), ['from' => '2026-01-01', 'to' => '2026-01-31', 'formal' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['formal']);

        expect(Run::where('group_id', $this->groupId)->count())->toBe(0);
    });

    it('is refused while an intragroup pair is an exception', function () {
        fmPost('1112', '4110', (string) Str::ulid());

        $response = fmRun(['formal' => true])->assertUnprocessable()->assertJsonValidationErrors(['formal']);

        expect($response->json('errors.formal.0'))->toContain('1')
            ->and(Run::where('group_id', $this->groupId)->count())->toBe(0);
        fmRun()->assertCreated();
    });

    it('is refused without a mapping, and says so', function () {
        $group = $this->actingAs($this->admin)->postJson('/api/v1/groups', [
            'name' => 'Sin', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR', 'effective_from' => '2026-01-01',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$group}/runs", ['from' => '2026-01-01', 'to' => '2026-01-31', 'formal' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['formal']);
    });
});
