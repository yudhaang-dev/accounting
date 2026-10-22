<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Str;

beforeEach(function () {
    // One user who runs two companies, as the accountant of a small group would.
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    [$this->childId] = createWorkspaceAs($this->admin, 'Anak');
});

function coCreateGroup(array $overrides = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson('/api/v1/groups', array_merge([
        'name' => 'Grup Acme',
        'parent_company_id' => test()->parentId,
        'presentation_currency' => 'IDR',
    ], $overrides));
}

function coGroupId(): string
{
    return coCreateGroup()->assertCreated()->json('data.id');
}

describe('groups (CO-FR-02, IA-FR-07)', function () {
    it('creates a group with its parent company as an approved member, and the creator may run and approve it', function () {
        $response = coCreateGroup()->assertCreated()
            ->assertJsonPath('data.name', 'Grup Acme')
            ->assertJsonPath('data.parent_company_id', $this->parentId)
            ->assertJsonPath('data.presentation_currency', 'IDR')
            ->assertJsonPath('data.lock_version', 1);

        $id = $response->json('data.id');
        $grants = app(GroupGrants::class);

        expect($grants->can($this->admin->id, $id, GroupAbility::Run))->toBeTrue()
            ->and($grants->can($this->admin->id, $id, GroupAbility::Approve))->toBeTrue()
            ->and(AuditEntry::where('event', 'group.created')->count())->toBe(1);

        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$id}/perimeter")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company_id', $this->parentId)
            ->assertJsonPath('data.0.status', 'approved');
    });

    it('needs the consent of the parent company: consolidation.join there', function () {
        $viewer = memberWithRoles($this->parentId, 'viewer');

        coCreateGroup(as: $viewer)->assertForbidden();
        coCreateGroup(['parent_company_id' => (string) Str::ulid()])->assertForbidden();

        expect(Group::count())->toBe(0);
    });

    it('rejects an unknown currency or company', function () {
        coCreateGroup(['presentation_currency' => 'XXQ'])->assertUnprocessable()->assertJsonValidationErrors(['presentation_currency']);

        $stranger = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($stranger, 'Lain');
        // A company that exists but where the user has no permission is refused, not revealed.
        coCreateGroup(['parent_company_id' => $otherCompany])->assertForbidden();
    });

    it('lists only the groups the user holds a grant on, not those of the companies they belong to (IA-AC-01)', function () {
        $mine = coGroupId();
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Lain');
        $theirs = coCreateGroup(['name' => 'Grup Lain', 'parent_company_id' => $otherCompany], $other)->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->getJson('/api/v1/groups')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);

        // Belonging to both workspaces opens neither group to a user without a grant.
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$theirs}")->assertForbidden();
        $this->actingAs($other)->getJson("/api/v1/groups/{$mine}")->assertForbidden();
    });

    it('renames a group with its lock version, and keeps the currency and parent', function () {
        $id = coGroupId();

        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$id}", ['lock_version' => 1, 'name' => 'Grup Baru'])
            ->assertOk()->assertJsonPath('data.name', 'Grup Baru')->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.presentation_currency', 'IDR');

        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$id}", ['lock_version' => 1, 'name' => 'Basi'])->assertStatus(409);

        $reader = User::factory()->create();
        app(GroupGrants::class)->grant($reader->id, $id, GroupAbility::Read);
        $this->actingAs($reader)->getJson("/api/v1/groups/{$id}")->assertOk();
        $this->actingAs($reader)->patchJson("/api/v1/groups/{$id}", ['lock_version' => 2, 'name' => 'X'])->assertForbidden();
    });
});

describe('group chart of accounts (CO-FR-04)', function () {
    it('creates, renames and deactivates accounts, and never changes their class', function () {
        $id = coGroupId();

        $account = $this->actingAs($this->admin)->postJson("/api/v1/groups/{$id}/accounts", ['code' => '1000', 'name' => 'Kas', 'class' => 'asset'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data');

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$id}/accounts", ['code' => '1000', 'name' => 'Lagi', 'class' => 'asset'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$id}/accounts", ['code' => '2000', 'name' => 'X', 'class' => 'bogus'])
            ->assertUnprocessable()->assertJsonValidationErrors(['class']);

        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$id}/accounts/{$account['id']}", ['lock_version' => 1, 'name' => 'Kas dan Bank', 'is_active' => false, 'class' => 'liability'])
            ->assertOk()->assertJsonPath('data.name', 'Kas dan Bank')->assertJsonPath('data.is_active', false)->assertJsonPath('data.class', 'asset');

        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$id}/accounts/{$account['id']}", ['lock_version' => 1, 'name' => 'Basi'])->assertStatus(409);
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$id}/accounts")->assertOk()->assertJsonCount(1, 'data');
    });

    it('is the group chart only: another group does not see or change it', function () {
        $first = coGroupId();
        $second = coCreateGroup(['name' => 'Grup Dua'])->assertCreated()->json('data.id');

        $account = $this->actingAs($this->admin)->postJson("/api/v1/groups/{$first}/accounts", ['code' => '1000', 'name' => 'Kas', 'class' => 'asset'])->json('data');

        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$second}/accounts")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->patchJson("/api/v1/groups/{$second}/accounts/{$account['id']}", ['lock_version' => 1, 'name' => 'X'])->assertNotFound();
    });
});

describe('perimeter (CO-FR-02)', function () {
    beforeEach(function () {
        $this->groupId = coGroupId();
        $this->approver = User::factory()->create();
        app(GroupGrants::class)->grant($this->approver->id, $this->groupId, GroupAbility::Approve);
    });

    function coPropose(string $groupId, string $companyId, ?User $as = null)
    {
        return test()->actingAs($as ?? test()->admin)->postJson("/api/v1/groups/{$groupId}/perimeter", [
            'company_id' => $companyId,
            'control_basis' => 'The parent holds the majority of votes and appoints the board.',
            'effective_from' => '2026-01-01',
        ]);
    }

    it('counts a proposed member only once someone other than the proposer approves it', function () {
        $member = coPropose($this->groupId, $this->childId)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/approve")
            ->assertForbidden()->assertJsonPath('code', 'SEGREGATION_OF_DUTIES');

        $this->actingAs($this->approver)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $this->approver->id);

        $this->actingAs($this->approver)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        expect(AuditEntry::where('event', 'group_member.proposed')->where('company_id', $this->childId)->count())->toBe(1)
            ->and(AuditEntry::where('event', 'group_member.approved')->where('company_id', $this->childId)->count())->toBe(1);
    });

    it('does not let running the group stand in for approving it', function () {
        $member = coPropose($this->groupId, $this->childId)->assertCreated()->json('data');
        $runner = User::factory()->create();
        app(GroupGrants::class)->grant($runner->id, $this->groupId, GroupAbility::Run);

        $this->actingAs($runner)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/approve")->assertForbidden();
    });

    it('needs the consent of the company: consolidation.join there', function () {
        $stranger = User::factory()->create();
        [$strangerCompany] = createWorkspaceAs($stranger, 'Asing');

        // The user runs the group but has no say in the other company.
        coPropose($this->groupId, $strangerCompany)->assertForbidden();
        coPropose($this->groupId, (string) Str::ulid())->assertForbidden();

        expect(PerimeterMember::where('company_id', $strangerCompany)->count())->toBe(0);
    });

    it('has a company in the perimeter once at a time, and again after it left', function () {
        $member = coPropose($this->groupId, $this->childId)->assertCreated()->json('data');

        coPropose($this->groupId, $this->childId)->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
        coPropose($this->groupId, $this->parentId)->assertUnprocessable()->assertJsonValidationErrors(['company_id']);

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/end", ['effective_to' => '2025-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors(['effective_to']);
        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/end", ['effective_to' => '2026-06-30'])
            ->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.effective_to', '2026-06-30');
        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$member['id']}/end", ['effective_to' => '2026-06-30'])
            ->assertStatus(409);

        coPropose($this->groupId, $this->childId)->assertCreated();
        $this->actingAs($this->admin)->getJson("/api/v1/groups/{$this->groupId}/perimeter")->assertOk()->assertJsonCount(3, 'data');
    });

    it('keeps the parent company in the perimeter', function () {
        $parent = PerimeterMember::where('group_id', $this->groupId)->where('company_id', $this->parentId)->firstOrFail();

        $this->actingAs($this->admin)->postJson("/api/v1/groups/{$this->groupId}/perimeter/{$parent->id}/end", ['effective_to' => '2026-12-31'])
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });
});
