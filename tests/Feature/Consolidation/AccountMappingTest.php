<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\MappingRow;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    [$this->childId] = createWorkspaceAs($this->admin, 'Anak');

    foreach ([$this->parentId, $this->childId] as $companyId) {
        confirmChartOfAccounts($this->admin, $companyId);
    }

    $this->groupId = test()->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup Acme', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR',
    ])->assertCreated()->json('data.id');

    // The child joins once someone else has approved the assessment of control.
    $approver = User::factory()->create();
    app(GroupGrants::class)->grant($approver->id, $this->groupId, GroupAbility::Approve);
    $memberId = $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter", [
        'company_id' => $this->childId, 'control_basis' => 'Majority of votes.', 'effective_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');
    $this->actingAs($approver)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$memberId}/approve")->assertOk();

    $this->cash = coGroupAccount('1000', 'Cash', 'asset');
    $this->revenue = coGroupAccount('4000', 'Revenue', 'income');
});

function coGroupAccount(string $code, string $name, string $class): string
{
    return test()->actingAs(test()->admin)->postJson('/api/v1/groups/'.test()->groupId.'/accounts', ['code' => $code, 'name' => $name, 'class' => $class])
        ->assertCreated()->json('data.id');
}

function coCompanyAccount(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function coSaveMapping(array $mappings, int $version = 0, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->putJson('/api/v1/groups/'.test()->groupId.'/account-mappings', ['lock_version' => $version, 'mappings' => $mappings]);
}

function coMappingRow(string $companyId, string $code, string $groupAccountId): array
{
    return ['company_id' => $companyId, 'account_id' => coCompanyAccount($companyId, $code), 'group_account_id' => $groupAccountId];
}

describe('group account mapping (CO-FR-04, P-04)', function () {
    it('is empty before anything is mapped', function () {
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/account-mappings")
            ->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.mappings', []);
    });

    it('maps accounts of different companies, with different codes, to one group account', function () {
        coSaveMapping([
            coMappingRow($this->parentId, '1112', $this->cash),
            coMappingRow($this->childId, '1111', $this->cash),
            coMappingRow($this->parentId, '4110', $this->revenue),
        ])->assertOk()->assertJsonPath('data.version', 1)->assertJsonCount(3, 'data.mappings');

        $rows = $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/account-mappings")->assertOk()->json('data.mappings');

        expect(collect($rows)->where('group_account_id', $this->cash)->pluck('account_code')->sort()->values()->all())->toBe(['1111', '1112'])
            ->and(AuditEntry::where('event', 'group_mapping.saved')->count())->toBe(1);
    });

    it('adds a version for each save and keeps the earlier ones as they were', function () {
        coSaveMapping([coMappingRow($this->parentId, '1112', $this->cash)])->assertOk();
        coSaveMapping([coMappingRow($this->parentId, '1112', $this->cash), coMappingRow($this->childId, '1111', $this->cash)], 1)
            ->assertOk()->assertJsonPath('data.version', 2);
        // Rows left out are no longer mapped: the mapping is replaced as a whole.
        coSaveMapping([coMappingRow($this->childId, '1111', $this->cash)], 2)->assertOk()->assertJsonPath('data.version', 3);

        $counts = MappingRow::query()->join('co_mapping_versions', 'co_mapping_versions.id', '=', 'co_mapping_rows.mapping_version_id')
            ->selectRaw('co_mapping_versions.version as version, count(*) as total')->groupBy('co_mapping_versions.version')->orderBy('version')->pluck('total', 'version')->all();

        expect($counts)->toBe([1 => 1, 2 => 2, 3 => 1]);
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/account-mappings")->assertJsonPath('data.version', 3)->assertJsonCount(1, 'data.mappings');
    });

    it('refuses a save made from an outdated version', function () {
        coSaveMapping([coMappingRow($this->parentId, '1112', $this->cash)])->assertOk();

        coSaveMapping([], 0)->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('maps only accounts of approved members', function () {
        $stranger = User::factory()->create();
        [$strangerCompany] = createWorkspaceAs($stranger, 'Asing');
        confirmChartOfAccounts($stranger, $strangerCompany);

        coSaveMapping([coMappingRow($strangerCompany, '1111', $this->cash)])->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.company_id']);
    });

    it('does not map a member whose assessment is still waiting for approval', function () {
        $third = User::factory()->create();
        [$thirdCompany] = createWorkspaceAs($third, 'Ketiga');
        confirmChartOfAccounts($third, $thirdCompany);
        // The admin is made a member of the third company too, so the proposal can be made.
        $workspace = Workspace::where('company_id', $thirdCompany)->firstOrFail();
        Membership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $this->admin->id]);
        Role::where('company_id', $thirdCompany)->where('name', 'accounting_admin')->firstOrFail()->users()->attach($this->admin->id, ['company_id' => $thirdCompany]);

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter", [
            'company_id' => $thirdCompany, 'control_basis' => 'Pending.', 'effective_from' => '2026-01-01',
        ])->assertCreated();

        coSaveMapping([coMappingRow($thirdCompany, '1111', $this->cash)])->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.company_id']);
    });

    it('checks the accounts: they exist in the company, are active and of the class of the group account', function () {
        $parentAccount = coCompanyAccount($this->parentId, '1112');

        // An account of the other company, an unknown one, a group account of another class.
        coSaveMapping([['company_id' => $this->childId, 'account_id' => $parentAccount, 'group_account_id' => $this->cash]])
            ->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.account_id']);
        coSaveMapping([['company_id' => $this->parentId, 'account_id' => (string) Str::ulid(), 'group_account_id' => $this->cash]])
            ->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.account_id']);
        coSaveMapping([coMappingRow($this->parentId, '1112', $this->revenue)])
            ->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.group_account_id']);
        coSaveMapping([['company_id' => $this->parentId, 'account_id' => $parentAccount, 'group_account_id' => (string) Str::ulid()]])
            ->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.group_account_id']);
    });

    it('refuses an inactive group account and a repeated account', function () {
        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$this->groupId}/accounts/{$this->cash}", ['lock_version' => 1, 'is_active' => false])->assertOk();

        coSaveMapping([coMappingRow($this->parentId, '1112', $this->cash)])->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.group_account_id']);

        $other = coGroupAccount('1100', 'Bank', 'asset');
        coSaveMapping([coMappingRow($this->parentId, '1112', $other), coMappingRow($this->parentId, '1112', $other)])
            ->assertUnprocessable()->assertJsonValidationErrors(['mappings.1.account_id']);
    });

    it('lists the accounts of an approved member for the mapping, and of no other company', function () {
        $accounts = $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/member-accounts?company_id={$this->childId}")->assertOk()->json('data');

        expect(array_column($accounts, 'code'))->toContain('1111', '4110')->and(array_column($accounts, 'id'))->toContain(coCompanyAccount($this->childId, '1111'));
        expect($accounts[0])->toHaveKeys(['id', 'code', 'name', 'class']);

        $stranger = User::factory()->create();
        [$strangerCompany] = createWorkspaceAs($stranger, 'Asing');
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/member-accounts?company_id={$strangerCompany}")->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/member-accounts")->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/groups/{$this->groupId}/member-accounts?company_id={$this->childId}")->assertForbidden();
    });

    it('is read with a read grant and changed only with a run grant', function () {
        $reader = User::factory()->create();
        app(GroupGrants::class)->grant($reader->id, $this->groupId, GroupAbility::Read);

        $this->actingAs($reader)->getJson("/api/v1/groups/{$this->groupId}/account-mappings")->assertOk();
        coSaveMapping([], 0, $reader)->assertForbidden();
        coSaveMapping([], 0, User::factory()->create())->assertForbidden();
    });
});
