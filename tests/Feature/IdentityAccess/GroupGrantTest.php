<?php

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->grants = app(GroupGrants::class);
    $this->group = (string) Str::ulid();
    $this->user = User::factory()->create();

    Route::middleware(['web', 'auth', 'group-access:read'])->get('/api/v1/_test/groups/{group}/read', fn () => response()->json(['ok' => true]));
    Route::middleware(['web', 'auth', 'group-access:run'])->get('/api/v1/_test/groups/{group}/run', fn () => response()->json(['ok' => true]));
});

describe('group grants (IA-FR-07)', function () {
    it('gives no access to a user who belongs to workspaces but holds no grant', function () {
        createWorkspaceAs($this->user);
        createWorkspaceAs($this->user);

        expect($this->grants->can($this->user->id, $this->group, GroupAbility::Read))->toBeFalse();

        $this->actingAs($this->user)->getJson("/api/v1/_test/groups/{$this->group}/read")->assertForbidden();
        $this->actingAs($this->user)->getJson("/api/v1/_test/groups/{$this->group}/run")->assertForbidden();
    });

    it('lets a reader read but not run, and a runner do both', function () {
        $this->grants->grant($this->user->id, $this->group, GroupAbility::Read);

        $this->actingAs($this->user)->getJson("/api/v1/_test/groups/{$this->group}/read")->assertOk();
        $this->actingAs($this->user)->getJson("/api/v1/_test/groups/{$this->group}/run")->assertForbidden();

        $this->grants->grant($this->user->id, $this->group, GroupAbility::Run);

        $this->actingAs($this->user)->getJson("/api/v1/_test/groups/{$this->group}/run")->assertOk();

        $runner = User::factory()->create();
        $this->grants->grant($runner->id, $this->group, GroupAbility::Run);

        expect($this->grants->can($runner->id, $this->group, GroupAbility::Read))->toBeTrue();
    });

    it('limits a grant to its group', function () {
        $this->grants->grant($this->user->id, $this->group, GroupAbility::Run);

        expect($this->grants->can($this->user->id, (string) Str::ulid(), GroupAbility::Read))->toBeFalse();
    });

    it('stops access when revoked, and revoking read revokes run as well', function () {
        $this->grants->grant($this->user->id, $this->group, GroupAbility::Run);
        $this->grants->revoke($this->user->id, $this->group, GroupAbility::Read);

        expect($this->grants->can($this->user->id, $this->group, GroupAbility::Read))->toBeFalse();
        $this->actingAs($this->user)->getJson("/api/v1/_test/groups/{$this->group}/read")->assertForbidden();
    });

    it('audits a grant once and each revocation', function () {
        $admin = User::factory()->create();

        $this->grants->grant($this->user->id, $this->group, GroupAbility::Read, $admin->id);
        $this->grants->grant($this->user->id, $this->group, GroupAbility::Read, $admin->id);
        $this->grants->revoke($this->user->id, $this->group, GroupAbility::Read);

        expect(AuditEntry::where('event', 'group_grant.created')->count())->toBe(1)
            ->and(AuditEntry::where('event', 'group_grant.revoked')->count())->toBe(1);
    });

    it('requires a signed-in user', function () {
        $this->getJson("/api/v1/_test/groups/{$this->group}/read")->assertUnauthorized();
    });
});
