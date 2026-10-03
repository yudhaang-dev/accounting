<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Platform\Api\ApiException;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);

    $this->receivables = controlAccountId($this->companyId, '1121');
    $this->payables = controlAccountId($this->companyId, '2110');
    $this->bank = controlAccountId($this->companyId, '1112');
    $this->parent = controlAccountId($this->companyId, '1120');

    // The template does not restrict the sources of control accounts, so the company does it.
    foreach ([$this->receivables, $this->payables] as $id) {
        $version = $this->actingAs($this->admin)->getJson("{$this->url}/accounts/{$id}")->json('data.lock_version');
        $this->actingAs($this->admin)->patchJson("{$this->url}/accounts/{$id}", ['lock_version' => $version, 'allowed_sources' => ['subledger']])->assertOk();
    }
});

function controlAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function newProfile(array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/posting-profiles', array_merge(['name' => 'Third parties'], $overrides));
}

function mapControl(string $profileId, string $role, string $accountId, int $version = 0, array $extra = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->putJson(test()->url."/posting-profiles/{$profileId}/accounts/{$role}", ['lock_version' => $version, 'account_id' => $accountId] + $extra);
}

function controlFailure(string $companyId, ControlRole $role, ?string $partyId = null, ?string $asOf = null): ApiException
{
    try {
        app(ControlAccounts::class)->require($companyId, $role, $partyId, $asOf);
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the control account to be refused.');
}

describe('posting profiles (SL-FR-02)', function () {
    it('makes the first profile the default and moves the default only to another profile', function () {
        $first = newProfile()->assertCreated()->assertJsonPath('data.is_default', true)->json('data');
        $second = newProfile(['name' => 'Related parties'])->assertCreated()->assertJsonPath('data.is_default', false)->json('data');

        expect($first['accounts'])->toBe([
            ['key' => 'receivables_control', 'account_id' => null, 'effective_date' => null, 'valid' => false, 'lock_version' => 0],
            ['key' => 'payables_control', 'account_id' => null, 'effective_date' => null, 'valid' => false, 'lock_version' => 0],
        ]);

        $this->actingAs($this->admin)->patchJson("{$this->url}/posting-profiles/{$first['id']}", ['lock_version' => 1, 'is_default' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_default']);

        $this->actingAs($this->admin)->patchJson("{$this->url}/posting-profiles/{$second['id']}", ['lock_version' => 1, 'is_default' => true, 'name' => 'Related'])
            ->assertOk()->assertJsonPath('data.is_default', true)->assertJsonPath('data.name', 'Related');

        $defaults = collect($this->actingAs($this->admin)->getJson("{$this->url}/posting-profiles")->assertOk()->json('data'))->where('is_default', true);
        expect($defaults->pluck('id')->all())->toBe([$second['id']]);

        $this->actingAs($this->admin)->patchJson("{$this->url}/posting-profiles/{$first['id']}", ['lock_version' => 1, 'name' => 'Basi'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('keeps profile names unique per company', function () {
        newProfile()->assertCreated();
        newProfile()->assertUnprocessable()->assertJsonValidationErrors(['name']);
    });

    it('maps a control role as a new version, keeps the earlier one and audits the change', function () {
        $id = newProfile()->assertCreated()->json('data.id');
        $other = $this->actingAs($this->admin)->postJson("{$this->url}/accounts", [
            'code' => '1127', 'name' => 'Piutang Pihak Berelasi', 'class' => 'asset', 'parent_id' => $this->parent, 'allowed_sources' => ['subledger'],
        ])->assertCreated()->json('data.id');

        mapControl($id, 'receivables_control', $this->receivables, 0, ['effective_date' => '2026-01-01'])
            ->assertOk()->assertJsonPath('data.accounts.0.account_id', $this->receivables)->assertJsonPath('data.accounts.0.lock_version', 1)->assertJsonPath('data.accounts.0.valid', true);
        mapControl($id, 'receivables_control', $other, 1, ['effective_date' => '2026-07-01'])->assertOk()->assertJsonPath('data.accounts.0.lock_version', 2);
        mapControl($id, 'receivables_control', $other, 1)->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        $controls = app(ControlAccounts::class);

        expect($controls->require($this->companyId, ControlRole::Receivables, null, '2026-03-31')->accountId)->toBe($this->receivables)
            ->and($controls->require($this->companyId, ControlRole::Receivables, null, '2026-07-01')->accountId)->toBe($other)
            ->and($controls->require($this->companyId, ControlRole::Receivables)->version)->toBe(2)
            ->and(DB::table('sl_posting_profile_accounts')->count())->toBe(2);

        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'posting_profile.account_changed')->orderBy('id')->get();
        expect($audit)->toHaveCount(2)
            ->and($audit[1]->attribute_changes['old']['account_id'])->toBe($this->receivables)
            ->and($audit[1]->attribute_changes['attributes']['account_id'])->toBe($other);
    });

    it('accepts only active leaf accounts of the right class that take the subledger source and nothing else', function () {
        $id = newProfile()->assertCreated()->json('data.id');
        $mixed = $this->actingAs($this->admin)->postJson("{$this->url}/accounts", [
            'code' => '1128', 'name' => 'Piutang Campuran', 'class' => 'asset', 'parent_id' => $this->parent, 'allowed_sources' => ['subledger', 'entry'],
        ])->assertCreated()->json('data.id');

        mapControl($id, 'receivables_control', $this->parent)->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        mapControl($id, 'receivables_control', $this->payables)->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        mapControl($id, 'payables_control', $this->receivables)->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        mapControl($id, 'receivables_control', $mixed)->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        mapControl($id, 'receivables_control', str_repeat('0', 26))->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        mapControl($id, 'bank_control', $this->bank)->assertNotFound();

        mapControl($id, 'payables_control', $this->payables)->assertOk()->assertJsonPath('data.accounts.1.valid', true);

        expect(DB::table('sl_posting_profile_accounts')->count())->toBe(1);
    });

    it('refuses a posting through an unmapped, missing or no longer valid control account with MAPPING_REQUIRED', function () {
        expect(controlFailure($this->companyId, ControlRole::Receivables)->errorCode->value)->toBe('MAPPING_REQUIRED');

        $id = newProfile()->assertCreated()->json('data.id');
        expect(controlFailure($this->companyId, ControlRole::Receivables)->errors)->toHaveKey('receivables_control');

        mapControl($id, 'receivables_control', $this->receivables)->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts/{$this->receivables}/deactivate")->assertOk();

        expect(controlFailure($this->companyId, ControlRole::Receivables)->errors)->toHaveKey('receivables_control');
        $this->actingAs($this->admin)->getJson("{$this->url}/posting-profiles/{$id}")->assertJsonPath('data.accounts.0.valid', false);
    });

    it('resolves the control account of a party through its own profile, else the default', function () {
        $default = newProfile()->assertCreated()->json('data.id');
        $foreign = newProfile(['name' => 'Foreign'])->assertCreated()->json('data.id');
        $special = $this->actingAs($this->admin)->postJson("{$this->url}/accounts", [
            'code' => '1129', 'name' => 'Piutang Valas', 'class' => 'asset', 'parent_id' => $this->parent, 'allowed_sources' => ['subledger'],
        ])->assertCreated()->json('data.id');

        mapControl($default, 'receivables_control', $this->receivables)->assertOk();
        mapControl($foreign, 'receivables_control', $special)->assertOk();

        $plain = $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Biasa', 'is_customer' => true])->assertCreated();
        $abroad = $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'Luar Negeri', 'is_customer' => true, 'posting_profile_id' => $foreign])
            ->assertCreated()->assertJsonPath('data.posting_profile_id', $foreign);

        $controls = app(ControlAccounts::class);

        expect($plain->json('data.posting_profile_id'))->toBeNull()
            ->and($controls->require($this->companyId, ControlRole::Receivables, $plain->json('data.id'))->accountId)->toBe($this->receivables)
            ->and($controls->require($this->companyId, ControlRole::Receivables, $abroad->json('data.id'))->accountId)->toBe($special)
            ->and($controls->require($this->companyId, ControlRole::Receivables, $abroad->json('data.id'))->postingProfileId)->toBe($foreign);

        // The profile of the party lacks the payable role even though the default could have it.
        mapControl($default, 'payables_control', $this->payables)->assertOk();
        expect(controlFailure($this->companyId, ControlRole::Payables, $abroad->json('data.id'))->errors)->toHaveKey('payables_control');

        $this->actingAs($this->admin)->patchJson("{$this->url}/parties/{$abroad->json('data.id')}", ['lock_version' => 1, 'posting_profile_id' => null])
            ->assertOk()->assertJsonPath('data.posting_profile_id', null);
    });

    it('rejects a posting profile that is not in the company', function () {
        $other = User::factory()->create();
        [$otherCompanyId] = createWorkspaceAs($other, 'Beta');
        $foreignProfile = $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompanyId}/posting-profiles", ['name' => 'Beta'])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("{$this->url}/parties", ['name' => 'X', 'is_customer' => true, 'posting_profile_id' => $foreignProfile])
            ->assertUnprocessable()->assertJsonValidationErrors(['posting_profile_id']);
        $this->actingAs($this->admin)->getJson("{$this->url}/posting-profiles/{$foreignProfile}")->assertNotFound();
    });

    it('lets a viewer read but not manage posting profiles', function () {
        $id = newProfile()->assertCreated()->json('data.id');
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/posting-profiles")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/posting-profiles/{$id}")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/posting-profiles", ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->url}/posting-profiles/{$id}", ['lock_version' => 1, 'name' => 'X'])->assertForbidden();
        mapControl($id, 'receivables_control', $this->receivables, 0, [], $viewer)->assertForbidden();
    });
});
