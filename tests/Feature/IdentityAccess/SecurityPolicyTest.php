<?php

use App\Models\User;
use App\Modules\IdentityAccess\Application\IdleSessionGuard;
use App\Platform\Audit\AuditEntry;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
    $this->policyUrl = "{$this->url}/security-policy";
});

function policyBody(array $overrides = []): array
{
    return array_merge([
        'lock_version' => 0,
        'two_factor_permissions' => [],
        'idle_timeout_minutes' => null,
        'client_ip_allowlist' => [],
    ], $overrides);
}

describe('policy versions (IA-FR-05, P-04)', function () {
    it('starts without requirements and adds a version per change, audited', function () {
        $this->actingAs($this->admin)->getJson($this->policyUrl)->assertOk()
            ->assertJsonPath('data.version', 0)->assertJsonPath('data.two_factor_permissions', [])->assertJsonPath('data.idle_timeout_minutes', null);

        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody([
            'two_factor_permissions' => ['entries.approve', 'periods.close'],
            'idle_timeout_minutes' => 30,
            'client_ip_allowlist' => ['203.0.113.7', '203.0.113.0/24', '2001:db8::/32'],
        ]))->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.two_factor_permissions', ['entries.approve', 'periods.close']);

        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['lock_version' => 1, 'idle_timeout_minutes' => 15]))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.idle_timeout_minutes', 15);
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['lock_version' => 1]))
            ->assertConflict()->assertJson(['code' => 'VERSION_CONFLICT']);

        $this->assertDatabaseCount('ia_security_policies', 2);
        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'security_policy.changed')->orderBy('id')->get();
        expect($audit)->toHaveCount(2)
            ->and($audit[1]->attribute_changes['old']['idle_timeout_minutes'])->toBe(30)
            ->and($audit[1]->attribute_changes['attributes']['idle_timeout_minutes'])->toBe(15);
    });

    it('validates permissions, session limit and addresses', function () {
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody([
            'two_factor_permissions' => ['admin.everything'],
            'idle_timeout_minutes' => 0,
            'client_ip_allowlist' => ['not-an-ip', '10.0.0.0/33', '10.0.0.1/x'],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'two_factor_permissions.0', 'idle_timeout_minutes', 'client_ip_allowlist.0', 'client_ip_allowlist.1', 'client_ip_allowlist.2',
        ]);
        $this->actingAs($this->admin)->putJson($this->policyUrl, ['lock_version' => 0])->assertUnprocessable();
    });

    it('is managed with policies.manage only and stays inside the company', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $this->actingAs($viewer)->getJson($this->policyUrl)->assertForbidden();
        $this->actingAs($viewer)->putJson($this->policyUrl, policyBody())->assertForbidden();

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['idle_timeout_minutes' => 10]))->assertOk();
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/security-policy")->assertOk()->assertJsonPath('data.version', 0);
    });
});

describe('2FA required for permissions (IA-AC-05)', function () {
    it('refuses a user without 2FA with the reason, and lets one with 2FA through', function () {
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['two_factor_permissions' => ['entries.approve', 'reports.read']]))->assertOk();
        $approver = memberWithRoles($this->companyId, 'approver');
        $missing = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

        $this->actingAs($approver)->postJson("{$this->url}/entries/{$missing}/approve")
            ->assertForbidden()->assertJson(['code' => 'TWO_FACTOR_REQUIRED'])->assertJsonStructure(['errors' => ['permission']]);

        $approver->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($approver->refresh())->postJson("{$this->url}/entries/{$missing}/approve")->assertNotFound();
    });

    it('leaves other permissions and users without the permission alone', function () {
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['two_factor_permissions' => ['entries.approve']]))->assertOk();
        $preparer = memberWithRoles($this->companyId, 'preparer');
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($preparer)->getJson("{$this->url}/entries")->assertOk();
        // Without the permission the answer stays a plain 403, not a 2FA request.
        $this->actingAs($viewer)->postJson("{$this->url}/entries/01HZZZZZZZZZZZZZZZZZZZZZZZ/approve")->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    });

    it('applies from the next request and stops when removed', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $reports = "{$this->url}/reports/trial-balance?from=2026-01-01&to=2026-01-31";

        $this->actingAs($viewer)->getJson($reports)->assertOk();
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['two_factor_permissions' => ['reports.read']]))->assertOk();
        $this->actingAs($viewer)->getJson($reports)->assertForbidden()->assertJson(['code' => 'TWO_FACTOR_REQUIRED']);
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['lock_version' => 1]))->assertOk();
        $this->actingAs($viewer)->getJson($reports)->assertOk();
    });
});

describe('idle session limit (IA-FR-05)', function () {
    it('signs out a session idle for longer than the limit and keeps an active one', function () {
        $this->actingAs($this->admin)->putJson($this->policyUrl, policyBody(['idle_timeout_minutes' => 30]))->assertOk();
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $key = IdleSessionGuard::sessionKey($this->companyId);

        $this->actingAs($viewer)->withSession([$key => now()->subMinutes(29)->getTimestamp()])
            ->getJson("{$this->url}/accounts")->assertOk();
        $this->actingAs($viewer)->withSession([$key => now()->subMinutes(31)->getTimestamp()])
            ->getJson("{$this->url}/accounts")->assertUnauthorized()->assertJson(['code' => 'UNAUTHENTICATED']);
    });

    it('does not time out when no limit is set', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->withSession([IdleSessionGuard::sessionKey($this->companyId) => now()->subYear()->getTimestamp()])
            ->getJson("{$this->url}/accounts")->assertOk();
    });
});
