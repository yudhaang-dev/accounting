<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Platform\Audit\AuditEntry;

describe('access:grant-permissions (D5b)', function () {
    it('adds permissions to the named role in every company and audits each change', function () {
        [$first] = createWorkspaceAs(User::factory()->create());
        [$second] = createWorkspaceAs(User::factory()->create());
        $viewer = fn (string $companyId) => Role::query()->where('company_id', $companyId)->where('name', 'viewer')->firstOrFail();
        $version = $viewer($first)->lock_version;

        expect($viewer($first)->permissionKeys())->not->toContain('reports.publish');

        $this->artisan('access:grant-permissions', ['role' => 'viewer', 'permissions' => ['reports.publish', 'plans.publish']])
            ->expectsOutputToContain('2 role(s) changed.')
            ->assertSuccessful();

        foreach ([$first, $second] as $companyId) {
            expect($viewer($companyId)->permissionKeys())->toContain('reports.publish', 'plans.publish');
        }

        expect($viewer($first)->lock_version)->toBe($version + 1)
            ->and(AuditEntry::query()->where('event', 'role.updated')->where('company_id', $first)->exists())->toBeTrue();

        // Other roles are untouched.
        expect(Role::query()->where('company_id', $first)->where('name', 'auditor')->firstOrFail()->permissionKeys())->not->toContain('plans.publish');
    });

    it('is idempotent and leaves a role that gains nothing untouched', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());
        $args = ['role' => 'viewer', 'permissions' => ['reports.publish']];

        $this->artisan('access:grant-permissions', $args)->assertSuccessful();
        $version = Role::query()->where('company_id', $companyId)->where('name', 'viewer')->firstOrFail()->lock_version;
        $audits = AuditEntry::query()->where('event', 'role.updated')->count();

        $this->artisan('access:grant-permissions', $args)->expectsOutputToContain('Nothing to change.')->assertSuccessful();

        expect(Role::query()->where('company_id', $companyId)->where('name', 'viewer')->firstOrFail()->lock_version)->toBe($version)
            ->and(AuditEntry::query()->where('event', 'role.updated')->count())->toBe($audits);
    });

    it('limits itself to one company with --company', function () {
        [$first] = createWorkspaceAs(User::factory()->create());
        [$second] = createWorkspaceAs(User::factory()->create());

        $this->artisan('access:grant-permissions', ['role' => 'viewer', 'permissions' => ['reports.publish'], '--company' => $first])->assertSuccessful();

        expect(Role::query()->where('company_id', $first)->where('name', 'viewer')->firstOrFail()->permissionKeys())->toContain('reports.publish')
            ->and(Role::query()->where('company_id', $second)->where('name', 'viewer')->firstOrFail()->permissionKeys())->not->toContain('reports.publish');
    });

    it('changes nothing on --dry-run', function () {
        [$companyId] = createWorkspaceAs(User::factory()->create());

        $this->artisan('access:grant-permissions', ['role' => 'viewer', 'permissions' => ['reports.publish'], '--dry-run' => true])
            ->expectsOutputToContain('Would grant reports.publish')
            ->assertSuccessful();

        expect(Role::query()->where('company_id', $companyId)->where('name', 'viewer')->firstOrFail()->permissionKeys())->not->toContain('reports.publish');
    });

    it('rejects a permission outside the catalog and a role that does not exist', function () {
        createWorkspaceAs(User::factory()->create());

        $this->artisan('access:grant-permissions', ['role' => 'viewer', 'permissions' => ['reports.nope']])
            ->expectsOutputToContain('Not in the permission catalog: reports.nope')
            ->assertFailed();

        $this->artisan('access:grant-permissions', ['role' => 'ghost', 'permissions' => ['reports.publish']])
            ->expectsOutputToContain('No role named [ghost]')
            ->assertFailed();
    });
});
