<?php

use App\Models\User;
use App\Modules\Consolidation\Domain\GroupRate;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->parentId] = createWorkspaceAs($this->admin, 'Induk');
    confirmChartOfAccounts($this->admin, $this->parentId);

    $this->groupId = test()->actingAs($this->admin)->postJson('/api/v1/groups', [
        'name' => 'Grup Acme', 'parent_company_id' => $this->parentId, 'presentation_currency' => 'IDR',
    ])->assertCreated()->json('data.id');

    $this->closing = trRateType('closing');
    $this->average = trRateType('average');
    $this->historical = trRateType('historical');
    $this->cta = trGroupAccount('3900', 'Translation difference', 'equity');
});

function trUrl(string $path): string
{
    return '/api/v1/groups/'.test()->groupId.'/'.$path;
}

function trRateType(string $code): string
{
    return test()->actingAs(test()->admin)->postJson(trUrl('rate-types'), ['code' => $code, 'name' => ucfirst($code)])->assertCreated()->json('data.id');
}

function trGroupAccount(string $code, string $name, string $class): string
{
    return test()->actingAs(test()->admin)->postJson(trUrl('accounts'), ['code' => $code, 'name' => $name, 'class' => $class])->assertCreated()->json('data.id');
}

function trPolicy(array $overrides = [], int $version = 0, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->putJson(trUrl('translation-policy'), [
        'lock_version' => $version,
        'closing_rate_type_id' => test()->closing,
        'average_rate_type_id' => test()->average,
        'historical_rate_type_id' => test()->historical,
        'difference_account_id' => test()->cta,
        ...$overrides,
    ]);
}

function trRate(string $rate, string $date = '2026-01-31', string $currency = 'USD', ?string $type = null, ?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(trUrl('rates'), [
        'rate_type_id' => $type ?? test()->closing, 'currency' => $currency, 'effective_date' => $date, 'rate' => $rate,
    ]);
}

describe('rate types of a group (CO-FR-06)', function () {
    it('creates, lists, renames, and deactivates them', function () {
        $this->actingAs($this->admin)->getJson(trUrl('rate-types'))->assertOk()->assertJsonCount(3, 'data');

        $this->actingAs($this->admin)->patchJson(trUrl("rate-types/{$this->historical}"), ['lock_version' => 1, 'name' => 'Rate at acquisition', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Rate at acquisition')->assertJsonPath('data.is_active', false)->assertJsonPath('data.lock_version', 2);

        expect(AuditEntry::where('event', 'group_rate_type.updated')->count())->toBe(1);
    });

    it('refuses a code taken by another type of the group, and a stale version', function () {
        $this->actingAs($this->admin)->postJson(trUrl('rate-types'), ['code' => 'closing', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($this->admin)->postJson(trUrl('rate-types'), ['code' => 'Bad Code', 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($this->admin)->patchJson(trUrl("rate-types/{$this->closing}"), ['lock_version' => 9, 'name' => 'x'])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('keeps a type the current policy names active', function () {
        trPolicy()->assertOk();

        $this->actingAs($this->admin)->patchJson(trUrl("rate-types/{$this->closing}"), ['lock_version' => 1, 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
    });
});

describe('translation policy (CO-FR-06, P-04)', function () {
    it('is empty before one is saved', function () {
        $this->actingAs($this->admin)->getJson(trUrl('translation-policy'))->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.difference_account_id', null);
    });

    it('adds a version for each save and keeps the earlier ones', function () {
        trPolicy()->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.difference_account_id', $this->cta);

        $other = trGroupAccount('3910', 'Other translation difference', 'equity');
        trPolicy(['difference_account_id' => $other], 1)->assertOk()->assertJsonPath('data.version', 2);

        $this->actingAs($this->admin)->getJson(trUrl('translation-policy'))->assertJsonPath('data.version', 2)->assertJsonPath('data.difference_account_id', $other);
        expect(DB::table('co_translation_policies')->where('group_id', $this->groupId)->count())->toBe(2)
            ->and(AuditEntry::where('event', 'group_translation_policy.saved')->count())->toBe(2);
    });

    it('refuses a save made from an outdated version', function () {
        trPolicy()->assertOk();

        trPolicy(version: 0)->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('takes its difference to an active equity account of the group only', function () {
        $asset = trGroupAccount('1000', 'Cash', 'asset');
        trPolicy(['difference_account_id' => $asset])->assertUnprocessable()->assertJsonValidationErrors(['difference_account_id']);
        trPolicy(['difference_account_id' => (string) Str::ulid()])->assertUnprocessable()->assertJsonValidationErrors(['difference_account_id']);

        $this->actingAs($this->admin)->patchJson(trUrl("accounts/{$this->cta}"), ['lock_version' => 1, 'is_active' => false])->assertOk();
        trPolicy()->assertUnprocessable()->assertJsonValidationErrors(['difference_account_id']);
    });

    it('names active rate types of the group only', function () {
        $this->actingAs($this->admin)->patchJson(trUrl("rate-types/{$this->average}"), ['lock_version' => 1, 'is_active' => false])->assertOk();

        trPolicy()->assertUnprocessable()->assertJsonValidationErrors(['average_rate_type_id']);
        trPolicy(['closing_rate_type_id' => (string) Str::ulid()])->assertUnprocessable()->assertJsonValidationErrors(['closing_rate_type_id', 'average_rate_type_id']);
    });

    it('reserves the difference account: nothing is mapped to it and it stays active', function () {
        trPolicy()->assertOk();

        $this->actingAs($this->admin)->patchJson(trUrl("accounts/{$this->cta}"), ['lock_version' => 1, 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);

        $account = DB::table('gl_accounts')->where('company_id', $this->parentId)->where('class', 'equity')->value('id');
        $this->actingAs($this->admin)->putJson(trUrl('account-mappings'), [
            'lock_version' => 0, 'mappings' => [['company_id' => $this->parentId, 'account_id' => $account, 'group_account_id' => $this->cta]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.group_account_id']);
    });
});

describe('group rates (CO-FR-06)', function () {
    it('records a rate, lists it, and does nothing for the same rate again', function () {
        trRate('16250.5')->assertCreated()->assertJsonPath('data.rate', '16250.500000000000')->assertJsonPath('data.version', 1)->assertJsonPath('data.source', 'manual');
        trRate('16250.5')->assertOk()->assertJsonPath('data.version', 1);

        expect(GroupRate::count())->toBe(1);
        $this->actingAs($this->admin)->getJson(trUrl('rates'))->assertOk()->assertJsonCount(1, 'data');
    });

    it('adds a version for a correction, which lookups and the default list prefer', function () {
        trRate('16250')->assertCreated();
        trRate('16300')->assertCreated()->assertJsonPath('data.version', 2);

        $this->actingAs($this->admin)->getJson(trUrl('rates'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 2);
        $this->actingAs($this->admin)->getJson(trUrl('rates?filter[include_superseded]=1'))->assertJsonCount(2, 'data');
        expect(AuditEntry::where('event', 'group_rate.corrected')->count())->toBe(1);
    });

    it('filters by type, currency, and dates', function () {
        trRate('16250', '2026-01-31');
        trRate('16100', '2026-02-28');
        trRate('16200', '2026-02-28', 'EUR');
        trRate('16000', '2026-02-28', 'USD', $this->average);

        $this->actingAs($this->admin)->getJson(trUrl("rates?filter[rate_type_id]={$this->closing}&filter[currency]=USD"))->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson(trUrl('rates?filter[from]=2026-02-01&filter[to]=2026-02-28'))->assertJsonCount(3, 'data');
    });

    it('refuses the presentation currency, an inactive or foreign type, and a bad rate', function () {
        trRate('1', currency: 'IDR')->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        trRate('16250', type: (string) Str::ulid())->assertUnprocessable()->assertJsonValidationErrors(['rate_type_id']);
        trRate('0')->assertUnprocessable()->assertJsonValidationErrors(['rate']);
        trRate('-5')->assertUnprocessable()->assertJsonValidationErrors(['rate']);
        trRate('16250', currency: 'usd')->assertUnprocessable()->assertJsonValidationErrors(['currency']);

        $this->actingAs($this->admin)->patchJson(trUrl("rate-types/{$this->historical}"), ['lock_version' => 1, 'is_active' => false])->assertOk();
        trRate('16250', type: $this->historical)->assertUnprocessable()->assertJsonValidationErrors(['rate_type_id']);
    });

    it('cannot be changed once recorded', function () {
        $id = trRate('16250')->json('data.id');

        expect(fn () => DB::table('co_group_rates')->where('id', $id)->update(['rate' => '1']))->toThrow(Exception::class);
    });
});

describe('access', function () {
    it('is read with a read grant and changed only with a run grant', function () {
        $reader = User::factory()->create();
        app(GroupGrants::class)->grant($reader->id, $this->groupId, GroupAbility::Read);

        $this->actingAs($reader)->getJson(trUrl('rate-types'))->assertOk();
        $this->actingAs($reader)->getJson(trUrl('rates'))->assertOk();
        $this->actingAs($reader)->getJson(trUrl('translation-policy'))->assertOk();

        trPolicy(as: $reader)->assertForbidden();
        trRate('16250', as: $reader)->assertForbidden();
        $this->actingAs($reader)->postJson(trUrl('rate-types'), ['code' => 'spot', 'name' => 'Spot'])->assertForbidden();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson(trUrl('rates'))->assertForbidden();
        trPolicy(as: $stranger)->assertForbidden();
    });
});
