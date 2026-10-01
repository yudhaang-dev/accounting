<?php

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
| Shared helpers for feature tests that need a company with members and roles.
*/

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

/** A member of the company workspace who holds exactly the named roles. */
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

/** Reviews and confirms the standard chart of accounts template, as a user would before activating. */
function confirmChartOfAccounts(User $admin, string $companyId): void
{
    test()->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/accounts/import-template", [
        'template' => 'general-id',
        'confirmed' => true,
    ])->assertCreated();
}

/** Sets up a monthly calendar starting in January and creates fiscal year 2026, so activation can pass. */
function setUpFiscalCalendar(User $admin, string $companyId, int $startMonth = 1, string $firstStart = '2026-01-01'): void
{
    test()->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/fiscal-calendar", [
        'pattern' => 'monthly',
        'start_month' => $startMonth,
    ])->assertCreated();

    test()->actingAs($admin)->postJson("/api/v1/companies/{$companyId}/fiscal-years", [
        'start_date' => $firstStart,
    ])->assertCreated();
}
