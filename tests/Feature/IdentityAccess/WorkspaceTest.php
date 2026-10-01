<?php

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Modules\MasterData\Domain\Company;
use App\Platform\Audit\AuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

class WorkspaceProbeController
{
    public function show(string $companyId): JsonResponse
    {
        return response()->json(['data' => ['company' => $companyId]]);
    }
}

beforeEach(function () {
    Route::prefix('api/v1')->middleware('api')->group(function () {
        Route::companyScoped(function () {
            Route::get('_probe/workspace', [WorkspaceProbeController::class, 'show']);
        });
    });
});

function newWorkspacePayload(array $overrides = []): array
{
    return array_merge([
        'workspace_name' => 'Acme',
        'company_name' => 'PT Acme Tbk',
        'functional_currency' => 'IDR',
    ], $overrides);
}

describe('POST /api/v1/workspaces (IA-FR-01)', function () {
    it('creates the workspace, its company in setup, and the creator membership', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/workspaces', newWorkspacePayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.company_name', 'PT Acme Tbk');

        $company = Company::findOrFail($response->json('data.company_id'));
        $workspace = Workspace::findOrFail($response->json('data.id'));

        expect($company->status->value)->toBe('setup')
            ->and($company->functional_currency)->toBe('IDR')
            ->and($company->timezone)->toBe('Asia/Jakarta')
            ->and($workspace->company_id)->toBe($company->id)
            ->and(Membership::where('workspace_id', $workspace->id)->where('user_id', $user->id)->count())->toBe(1);

        expect(AuditEntry::whereIn('event', ['company.created', 'workspace.created', 'membership.created'])
            ->where('company_id', $company->id)->count())->toBe(3);
    });

    it('rolls the company back when the workspace cannot be created', function () {
        $user = User::factory()->create();
        $this->mock(Workspaces::class)
            ->shouldReceive('createFor')->andThrow(new RuntimeException('boom'));

        $this->actingAs($user)->postJson('/api/v1/workspaces', newWorkspacePayload())->assertStatus(500);

        expect(Company::count())->toBe(0);
    });

    it('validates the payload', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/workspaces', ['functional_currency' => 'rupiah', 'timezone' => 'Mars/Base', 'default_locale' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['workspace_name', 'company_name', 'functional_currency', 'timezone', 'default_locale']);
    });

    it('does not create a second workspace for a repeated Idempotency-Key', function () {
        $user = User::factory()->create();
        $headers = ['Idempotency-Key' => 'create-acme'];

        $first = $this->actingAs($user)->postJson('/api/v1/workspaces', newWorkspacePayload(), $headers)->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/v1/workspaces', newWorkspacePayload(), $headers)->assertCreated();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and(Workspace::count())->toBe(1);
    });

    it('answers 401 UNAUTHENTICATED without a session', function () {
        $this->postJson('/api/v1/workspaces', newWorkspacePayload())
            ->assertUnauthorized()->assertJson(['code' => 'UNAUTHENTICATED']);
    });
});

describe('GET /api/v1/me/workspaces (IA-FR-01)', function () {
    it('lists only workspaces the user can currently access', function () {
        $user = User::factory()->create();
        $mine = Membership::factory()->for(Workspace::factory(['name' => 'Mine']))->create(['user_id' => $user->id]);
        Membership::factory()->revoked()->for(Workspace::factory(['name' => 'Revoked']))->create(['user_id' => $user->id]);
        Membership::factory()->expiredAt(now()->subDay())->for(Workspace::factory(['name' => 'Expired']))->create(['user_id' => $user->id]);
        Membership::factory()->for(Workspace::factory(['name' => 'Someone else']))->create();

        $this->actingAs($user)->getJson('/api/v1/me/workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->workspace_id)
            ->assertJsonPath('data.0.company_name', $mine->workspace->company_id ? Company::find($mine->workspace->company_id)->name : null);
    });

    it('answers 401 UNAUTHENTICATED without a session', function () {
        $this->getJson('/api/v1/me/workspaces')->assertUnauthorized()->assertJson(['code' => 'UNAUTHENTICATED']);
    });
});

describe('company access through membership', function () {
    it('only reaches the companies of workspaces the user belongs to (IA-AC-01)', function () {
        $user = User::factory()->create();
        $one = Workspace::factory()->create();
        $two = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        Membership::factory()->create(['workspace_id' => $one->id, 'user_id' => $user->id]);
        Membership::factory()->create(['workspace_id' => $two->id, 'user_id' => $user->id]);

        $this->actingAs($user)->getJson("/api/v1/companies/{$one->company_id}/_probe/workspace")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/companies/{$two->company_id}/_probe/workspace")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/companies/{$other->company_id}/_probe/workspace")
            ->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    });

    it('stops at the next request once the membership is revoked or expired (IA-AC-02)', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $membership = Membership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
        $url = "/api/v1/companies/{$workspace->company_id}/_probe/workspace";

        $this->actingAs($user)->getJson($url)->assertOk();

        $membership->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->getJson($url)->assertForbidden();

        $membership->update(['expires_at' => null, 'status' => 'revoked']);
        $this->actingAs($user)->getJson($url)->assertForbidden();

        expect(Membership::count())->toBe(1);
    });
});
