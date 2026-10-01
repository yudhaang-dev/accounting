<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\SyncPermissionCatalog;
use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Platform\Audit\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

class PermissionProbeController
{
    public function show(string $companyId): JsonResponse
    {
        return response()->json(['data' => ['company' => $companyId]]);
    }
}

beforeEach(function () {
    Route::prefix('api/v1')->middleware('api')->group(function () {
        Route::companyScoped(function () {
            Route::get('_probe/read', [PermissionProbeController::class, 'show'])->middleware('permission:entries.read');
            Route::get('_probe/export', [PermissionProbeController::class, 'show'])->middleware('permission:reports.export');
            Route::get('_probe/both', [PermissionProbeController::class, 'show'])->middleware('permission:entries.read,reports.export');
        });
    });
});

/** A workspace created through the API, so roles are seeded the way they are in production. */
function createWorkspaceAs(User $user, string $name = 'Acme'): array
{
    $response = test()->actingAs($user)->postJson('/api/v1/workspaces', [
        'workspace_name' => $name,
        'company_name' => "PT {$name}",
        'functional_currency' => 'IDR',
    ])->assertCreated();

    return [$response->json('data.company_id'), $response];
}

/** A member of the company's workspace who holds exactly the named roles. */
function memberWithRoles(string $companyId, string ...$roleNames): User
{
    $user = User::factory()->create();
    $workspace = Workspace::where('company_id', $companyId)->firstOrFail();
    Membership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    foreach ($roleNames as $name) {
        Role::where('company_id', $companyId)->where('name', $name)->firstOrFail()
            ->users()->attach($user->id, ['company_id' => $companyId]);
    }

    return $user;
}

describe('permission catalog (IA-FR-02)', function () {
    it('is mirrored into the permissions table idempotently', function () {
        $sync = app(SyncPermissionCatalog::class);

        expect($sync())->toBe(count(PermissionCatalog::all()))
            ->and($sync())->toBe(0)
            ->and(Permission::count())->toBe(count(PermissionCatalog::all()));

        $this->artisan('access:sync-permissions')->expectsOutput('Permission catalog is up to date.')->assertSuccessful();
    });

    it('formats every key as resource.action', function () {
        foreach (PermissionCatalog::all() as $key) {
            expect($key)->toMatch('/^[a-z-]+\.[a-z-]+$/');
        }

        expect(PermissionCatalog::has('entries.approve'))->toBeTrue()
            ->and(PermissionCatalog::has('periods.close'))->toBeTrue()
            ->and(PermissionCatalog::has('reports.export'))->toBeTrue();
    });

    it('only lets role templates use catalog permissions', function () {
        foreach (config('role-templates.templates') as $template) {
            expect(array_diff($template['permissions'], PermissionCatalog::all()))->toBe([], $template['name']);
        }
    });
});

describe('seed roles (IA-FR-02)', function () {
    it('seeds the template roles for the new company only and gives the creator the starting roles', function () {
        $creator = User::factory()->create();
        [$companyId, $response] = createWorkspaceAs($creator);
        [$otherCompanyId] = createWorkspaceAs(User::factory()->create(), 'Other');

        $templates = collect(config('role-templates.templates'));

        expect(Role::where('company_id', $companyId)->pluck('name')->sort()->values()->all())
            ->toBe($templates->pluck('name')->sort()->values()->all())
            ->and(Role::where('company_id', $otherCompanyId)->count())->toBe($templates->count());

        $expected = $templates->where('grant_to_creator', true)->pluck('permissions')->flatten()->unique()->sort()->values()->all();

        expect(app(AccessContexts::class)->forUser($creator->id, $companyId)->permissions)->toBe($expected)
            ->and($response->json('data.permissions'))->toBe($expected)
            ->and(app(AccessContexts::class)->forUser($creator->id, $otherCompanyId)->permissions)->toBe([]);

        expect(AuditEntry::where('company_id', $companyId)->whereIn('event', ['roles.seeded', 'roles.assigned'])->count())->toBe(2);
    });

    it('gives a member the union of the permissions of all their roles', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $both = memberWithRoles($companyId, 'viewer', 'auditor');
        $none = memberWithRoles($companyId);

        $contexts = app(AccessContexts::class);

        expect($contexts->forUser($viewer->id, $companyId)->can('reports.export'))->toBeFalse()
            ->and($contexts->forUser($both->id, $companyId)->can('reports.export'))->toBeTrue()
            ->and($contexts->forUser($both->id, $companyId)->can('entries.approve'))->toBeFalse()
            ->and($contexts->forUser($none->id, $companyId)->permissions)->toBe([]);
    });

    it('rejects a role assignment that points at another company role', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        [$otherCompanyId] = createWorkspaceAs(User::factory()->create(), 'Other');
        $foreignRole = Role::where('company_id', $otherCompanyId)->firstOrFail();

        expect(fn () => $foreignRole->users()->attach(User::factory()->create()->id, ['company_id' => $companyId]))
            ->toThrow(QueryException::class);
    });
});

describe('permission middleware (IA-FR-02, IA-AC-01)', function () {
    it('lets a member with the permission through and refuses one without it', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $noRole = memberWithRoles($companyId);

        $this->actingAs($viewer)->getJson("/api/v1/companies/{$companyId}/_probe/read")->assertOk();
        $this->actingAs($viewer)->getJson("/api/v1/companies/{$companyId}/_probe/export")
            ->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
        $this->actingAs($noRole)->getJson("/api/v1/companies/{$companyId}/_probe/read")->assertForbidden();
    });

    it('requires every listed permission', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $auditor = memberWithRoles($companyId, 'auditor');

        $this->actingAs($viewer)->getJson("/api/v1/companies/{$companyId}/_probe/both")->assertForbidden();
        $this->actingAs($auditor)->getJson("/api/v1/companies/{$companyId}/_probe/both")->assertOk();
    });

    it('does not carry permissions across companies', function () {
        $user = User::factory()->create();
        [$companyA] = createWorkspaceAs($user);
        [$companyB] = createWorkspaceAs(User::factory()->create(), 'Other');
        Membership::factory()->create(['workspace_id' => Workspace::where('company_id', $companyB)->value('id'), 'user_id' => $user->id]);

        $this->actingAs($user)->getJson("/api/v1/companies/{$companyA}/_probe/read")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/companies/{$companyB}/_probe/read")->assertForbidden();
    });

    it('has no permissions once the membership is revoked, whatever roles remain', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');

        Membership::where('user_id', $viewer->id)->update(['status' => 'revoked']);

        $this->actingAs($viewer)->getJson("/api/v1/companies/{$companyId}/_probe/read")->assertForbidden();
    });
});

describe('roles are data (IA-AC-03)', function () {
    it('applies a changed role permission to its holders on the next request', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $one = memberWithRoles($companyId, 'viewer');
        $two = memberWithRoles($companyId, 'viewer');
        $url = "/api/v1/companies/{$companyId}/_probe/export";

        $this->actingAs($one)->getJson($url)->assertForbidden();
        $this->actingAs($two)->getJson($url)->assertForbidden();

        $viewerRole = Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();
        $viewerRole->permissions()->attach(Permission::where('name', 'reports.export')->value('id'));

        $this->actingAs($one)->getJson($url)->assertOk();
        $this->actingAs($two)->getJson($url)->assertOk();

        $viewerRole->permissions()->detach(Permission::where('name', 'reports.export')->value('id'));

        $this->actingAs($one)->getJson($url)->assertForbidden();
    });

    it('does not change behaviour when a role is renamed', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $url = "/api/v1/companies/{$companyId}/_probe/read";

        $this->actingAs($viewer)->getJson($url)->assertOk();

        Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail()->update(['name' => 'senior-reader']);

        $this->actingAs($viewer)->getJson($url)->assertOk();
    });

    it('grants nothing from a deactivated role', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $viewer = memberWithRoles($companyId, 'viewer');
        $url = "/api/v1/companies/{$companyId}/_probe/read";

        $this->actingAs($viewer)->getJson($url)->assertOk();

        Role::where('company_id', $companyId)->where('name', 'viewer')->firstOrFail()->update(['is_active' => false]);

        $this->actingAs($viewer)->getJson($url)->assertForbidden();
    });
});

describe('GET /api/v1/me/workspaces permissions', function () {
    it('lists the effective permissions of the user per workspace', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $auditor = memberWithRoles($companyId, 'auditor');

        $this->actingAs($auditor)->getJson('/api/v1/me/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.company_id', $companyId)
            ->assertJsonPath('data.0.permissions', app(AccessContexts::class)->forUser($auditor->id, $companyId)->permissions);

        expect(app(AccessContexts::class)->forUser($auditor->id, $companyId)->can('audit.read'))->toBeTrue();
    });
});
