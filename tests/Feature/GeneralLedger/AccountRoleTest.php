<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);

    $this->retained = roleAccountId($this->companyId, '3130');
    $this->fxLoss = roleAccountId($this->companyId, '7230');
    $this->bank = roleAccountId($this->companyId, '1112');
    $this->currentAssets = roleAccountId($this->companyId, '1100');
});

function roleAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function mapRole(string $role, string $accountId, int $version = 0, array $extra = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->putJson(test()->url."/account-roles/{$role}", ['lock_version' => $version, 'account_id' => $accountId] + $extra);
}

function roleFailure(string $companyId, AccountRole $role, ?string $asOf = null): ApiException
{
    try {
        app(AccountRoles::class)->require($companyId, $role, $asOf);
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the role to be refused.');
}

describe('account roles (GL-FR-10)', function () {
    it('lists every role as unmapped for a new company', function () {
        $response = $this->actingAs($this->admin)->getJson("{$this->url}/account-roles")->assertOk();

        expect(collect($response->json('data'))->pluck('key')->all())->toBe([
            'retained_earnings', 'fx_realized_gain', 'fx_realized_loss', 'fx_unrealized_gain', 'fx_unrealized_loss', 'fx_rounding',
        ])->and(collect($response->json('data'))->every(fn ($row) => $row['account_id'] === null && $row['lock_version'] === 0 && $row['valid'] === false))->toBeTrue();

        $response->assertJsonPath('data.0.required', 'year_end_close')->assertJsonPath('data.0.allowed_classes', ['equity'])
            ->assertJsonPath('data.5.required', 'foreign_currency')->assertJsonPath('data.5.allowed_classes', ['income', 'expense']);
    });

    it('maps a role as a new version, keeps the earlier one and audits the change', function () {
        mapRole('retained_earnings', $this->retained, 0, ['effective_date' => '2026-01-01'])
            ->assertOk()->assertJsonPath('data.lock_version', 1)->assertJsonPath('data.account_id', $this->retained);

        $other = $this->actingAs($this->admin)->postJson("{$this->url}/accounts", ['code' => '3140', 'name' => 'Saldo Laba Lain', 'class' => 'equity'])->assertCreated()->json('data.id');
        mapRole('retained_earnings', $other, 1, ['effective_date' => '2026-07-01'])->assertOk()->assertJsonPath('data.lock_version', 2);

        $this->actingAs($this->admin)->getJson("{$this->url}/account-roles")
            ->assertJsonPath('data.0.account_id', $other)->assertJsonPath('data.0.valid', true)->assertJsonPath('data.0.lock_version', 2);

        $roles = app(AccountRoles::class);

        // Earlier versions stay: a posting dated before the second mapping still finds the first one.
        expect($roles->current($this->companyId, AccountRole::RetainedEarnings, '2026-03-31')->accountId)->toBe($this->retained)
            ->and($roles->current($this->companyId, AccountRole::RetainedEarnings, '2026-07-01')->accountId)->toBe($other)
            ->and($roles->current($this->companyId, AccountRole::RetainedEarnings, '2025-12-31'))->toBeNull()
            ->and(DB::table('gl_account_role_mappings')->count())->toBe(2);

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'account_role.changed')->orderBy('id')->get();

        expect($audit)->toHaveCount(2)
            ->and($audit[1]->attribute_changes['old']['account_id'])->toBe($this->retained)
            ->and($audit[1]->attribute_changes['attributes']['account_id'])->toBe($other);
    });

    it('refuses a stale lock_version', function () {
        mapRole('fx_rounding', $this->fxLoss)->assertOk();

        mapRole('fx_rounding', $this->fxLoss, 0)->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        expect(DB::table('gl_account_role_mappings')->count())->toBe(1);
    });

    it('refuses a target that is a parent, inactive, of the wrong class or from another company', function () {
        mapRole('fx_rounding', $this->currentAssets)->assertUnprocessable()->assertJsonValidationErrors(['account_id']);

        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/{$this->fxLoss}/deactivate")->assertOk();
        mapRole('fx_rounding', $this->fxLoss)->assertUnprocessable()->assertJsonPath('errors.account_id.0', 'The account is inactive.');

        // An asset cannot be the account of a gain/loss role, nor an expense the retained earnings.
        mapRole('fx_realized_gain', $this->bank)->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        mapRole('retained_earnings', roleAccountId($this->companyId, '6220'))->assertUnprocessable()->assertJsonValidationErrors(['account_id']);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other);
        confirmChartOfAccounts($other, $otherCompany);
        mapRole('retained_earnings', roleAccountId($otherCompany, '3130'))->assertUnprocessable()->assertJsonPath('errors.account_id.0', 'The account does not exist in this company.');

        expect(DB::table('gl_account_role_mappings')->count())->toBe(0);
    });

    it('maps the same role to different accounts in two companies without one affecting the other (X-AC-02)', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other);
        confirmChartOfAccounts($other, $otherCompany);
        $otherAccount = $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/accounts", ['code' => '3199', 'name' => 'Laba Ditahan', 'class' => 'equity'])->assertCreated()->json('data.id');

        mapRole('retained_earnings', $this->retained)->assertOk();
        $this->actingAs($other)->putJson("/api/v1/companies/{$otherCompany}/account-roles/retained_earnings", ['lock_version' => 0, 'account_id' => $otherAccount])->assertOk();

        $roles = app(AccountRoles::class);

        expect($roles->current($this->companyId, AccountRole::RetainedEarnings)->accountId)->toBe($this->retained)
            ->and($roles->current($otherCompany, AccountRole::RetainedEarnings)->accountId)->toBe($otherAccount)
            ->and($roles->all($otherCompany))->toHaveCount(1);
    });

    it('answers MAPPING_REQUIRED for a role that is missing or whose account no longer qualifies (X-AC-03)', function () {
        $failure = roleFailure($this->companyId, AccountRole::FxRounding);
        expect($failure->errorCode->value)->toBe('MAPPING_REQUIRED')->and($failure->errors['fx_rounding'][0])->toContain('fx_rounding');

        mapRole('fx_rounding', $this->fxLoss, 0, ['effective_date' => '2026-01-01'])->assertOk();
        expect(app(AccountRoles::class)->require($this->companyId, AccountRole::FxRounding)->accountId)->toBe($this->fxLoss);

        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/{$this->fxLoss}/deactivate")->assertOk();
        $failure = roleFailure($this->companyId, AccountRole::FxRounding);
        expect($failure->errorCode->value)->toBe('MAPPING_REQUIRED')->and($failure->errors['fx_rounding'])->toContain('The account is inactive.');

        // Before the mapping took effect the role is unmapped for that date.
        expect(roleFailure($this->companyId, AccountRole::FxRounding, '2000-01-01')->errors['fx_rounding'][0])->toContain('not mapped');

        expect(app(AccountRoles::class)->missing($this->companyId, AccountRole::foreignCurrencyRoles()))->toHaveCount(4);
        $this->actingAs($this->admin)->getJson("{$this->url}/account-roles")->assertJsonPath('data.5.valid', false);
    });

    it('lets other modules check a role target by class and source', function () {
        $accounts = app(AccountDirectory::class);
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$this->bank}", ['lock_version' => 1, 'allowed_sources' => ['subledger']])->assertOk();

        expect($accounts->roleTargetProblems($this->companyId, $this->bank, [AccountClass::Asset], JournalSource::Subledger))->toBe([])
            ->and($accounts->roleTargetProblems($this->companyId, $this->bank, [AccountClass::Liability]))->toHaveCount(1)
            ->and($accounts->roleTargetProblems($this->companyId, $this->retained, [AccountClass::Equity], JournalSource::Subledger))->toBe([])
            ->and($accounts->roleTargetProblems($this->companyId, $this->bank, [AccountClass::Asset], JournalSource::Entry))->toHaveCount(1)
            ->and($accounts->roleTargetProblems($this->companyId, '01ARZ3NDEKTSV4RRFFQ69G5FAV', [AccountClass::Asset]))->toHaveCount(1);
    });

    it('requires accounts.manage to map and accounts.read to list', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/account-roles")->assertOk();
        mapRole('fx_rounding', $this->fxLoss, as: $viewer)->assertForbidden();
        $this->actingAs($this->admin)->putJson("{$this->url}/account-roles/unknown_role", ['lock_version' => 0, 'account_id' => $this->fxLoss])->assertNotFound();
    });
});
