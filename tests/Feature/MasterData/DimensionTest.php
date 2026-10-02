<?php

use App\Models\User;
use App\Modules\MasterData\Domain\DimensionType;
use App\Modules\MasterData\Domain\DimensionValue;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

function postDimensionType(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-types', array_merge([
        'key' => 'branch',
        'label' => 'Branch',
    ], $overrides));
}

function postDimensionValue(string $typeId, array $overrides = []): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', array_merge([
        'dimension_type_id' => $typeId,
        'code' => 'JKT',
        'name' => 'Jakarta',
    ], $overrides));
}

/** Records the value in a dimension set, as a transaction would once sets are resolved (M2-02). */
function useDimensionValue(string $companyId, string $typeId, string $valueId): void
{
    $setId = (string) Str::ulid();

    DB::table('md_dimension_sets')->insert(['id' => $setId, 'company_id' => $companyId, 'signature' => hash('sha256', $valueId), 'created_at' => now()]);
    DB::table('md_dimension_set_items')->insert([
        'dimension_set_id' => $setId, 'company_id' => $companyId, 'dimension_type_id' => $typeId, 'dimension_value_id' => $valueId,
    ]);
}

describe('dimension types (MD-FR-02)', function () {
    it('seeds department (hierarchical) and project as ordinary data for a new company', function () {
        $types = $this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->assertOk();

        expect(array_column($types->json('data'), 'key'))->toBe(['department', 'project'])
            ->and(array_column($types->json('data'), 'is_hierarchical'))->toBe([true, false]);

        $department = $types->json('data.0');

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-types/{$department['id']}", ['lock_version' => 1, 'label' => 'Divisi'])
            ->assertOk()->assertJsonPath('data.label', 'Divisi');
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$department['id']}/deactivate")->assertOk();
    });

    it('adds a new type without any schema change and uses it at once (MD-AC-03)', function () {
        $tables = DB::select("select count(*) as n from information_schema.columns where table_schema = current_schema() and table_name like 'md_dimension%'")[0]->n;

        $typeId = postDimensionType()->assertCreated()->assertJson(['data' => [
            'key' => 'branch', 'label' => 'Branch', 'is_hierarchical' => false, 'leaf_only' => false, 'status' => 'active', 'lock_version' => 1,
        ]])->json('data.id');

        postDimensionValue($typeId)->assertCreated();

        expect(DB::select("select count(*) as n from information_schema.columns where table_schema = current_schema() and table_name like 'md_dimension%'")[0]->n)->toBe($tables)
            ->and(AuditEntry::where('event', 'dimension_type.created')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('validates the key, keeps it unique per company and lets another company reuse it', function () {
        postDimensionType(['key' => 'Bad Key'])->assertUnprocessable()->assertJsonValidationErrors(['key']);
        postDimensionType(['key' => 'department'])->assertUnprocessable()->assertJsonValidationErrors(['key']);

        postDimensionType()->assertCreated();
        postDimensionType(['label' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['key']);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/dimension-types", ['key' => 'branch', 'label' => 'Theirs'])->assertCreated();
    });

    it('changes label and flags but never the key, and answers VERSION_CONFLICT for a stale version', function () {
        $id = postDimensionType()->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-types/{$id}", ['lock_version' => 1, 'label' => 'Cabang', 'key' => 'other', 'leaf_only' => true])
            ->assertOk()->assertJsonPath('data.key', 'branch')->assertJsonPath('data.label', 'Cabang')
            ->assertJsonPath('data.leaf_only', true)->assertJsonPath('data.lock_version', 2);

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-types/{$id}", ['lock_version' => 1, 'label' => 'Stale'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        expect(AuditEntry::where('event', 'dimension_type.updated')->count())->toBe(1);
    });

    it('refuses to drop the hierarchy while values have a parent', function () {
        $id = postDimensionType(['is_hierarchical' => true])->json('data.id');
        $root = postDimensionValue($id)->json('data.id');
        postDimensionValue($id, ['code' => 'JKT-1', 'name' => 'Jakarta 1', 'parent_id' => $root])->assertCreated();

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-types/{$id}", ['lock_version' => 1, 'is_hierarchical' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_hierarchical']);
    });

    it('deactivates and reactivates a type, and refuses new values while it is inactive', function () {
        $id = postDimensionType()->json('data.id');

        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$id}/deactivate")->assertStatus(409);
        postDimensionValue($id)->assertUnprocessable()->assertJsonValidationErrors(['dimension_type_id']);

        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        postDimensionValue($id)->assertCreated();
    });

    it('filters the list by status and never shows another company types', function () {
        $id = postDimensionType()->json('data.id');
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$id}/deactivate");

        $this->actingAs($this->admin)->getJson("{$this->url}/dimension-types?filter[status]=inactive")->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'branch');

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/dimension-types/{$id}")->assertNotFound();
    });
});

describe('dimension values (MD-FR-03)', function () {
    beforeEach(function () {
        $this->typeId = postDimensionType(['is_hierarchical' => true])->json('data.id');
    });

    it('creates a value with optional validity and keeps codes unique per type', function () {
        postDimensionValue($this->typeId, ['valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'])->assertCreated()->assertJson(['data' => [
            'code' => 'JKT', 'name' => 'Jakarta', 'parent_id' => null, 'is_leaf' => true,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'status' => 'active', 'lock_version' => 1,
        ]]);

        postDimensionValue($this->typeId, ['name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        // The same code is fine in another type.
        $otherType = postDimensionType(['key' => 'region'])->json('data.id');
        postDimensionValue($otherType)->assertCreated();
    });

    it('validates the payload, the validity order and the type', function () {
        postDimensionValue($this->typeId, ['code' => 'with space'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        postDimensionValue($this->typeId, ['valid_from' => '2026-06-01', 'valid_to' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['valid_to']);
        postDimensionValue('01J00000000000000000000000')->assertUnprocessable()->assertJsonValidationErrors(['dimension_type_id']);

        $id = postDimensionValue($this->typeId, ['valid_from' => '2026-03-01'])->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$id}", ['lock_version' => 1, 'valid_to' => '2026-02-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['valid_to']);
    });

    it('requires a parent of the same type that is active, and only for hierarchical types', function () {
        $root = postDimensionValue($this->typeId)->json('data.id');

        postDimensionValue($this->typeId, ['code' => 'JKT-1', 'parent_id' => $root])->assertCreated()->assertJsonPath('data.parent_id', $root);

        $flat = postDimensionType(['key' => 'region'])->json('data.id');
        $flatRoot = postDimensionValue($flat)->json('data.id');
        postDimensionValue($flat, ['code' => 'JKT-1', 'parent_id' => $flatRoot])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        // A parent of another type, or one that does not exist, is refused.
        postDimensionValue($this->typeId, ['code' => 'X-1', 'parent_id' => $flatRoot])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        postDimensionValue($this->typeId, ['code' => 'X-2', 'parent_id' => '01J00000000000000000000000'])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$root}/deactivate")->assertStatus(409);
    });

    it('rejects a parent that forms a cycle (MD-AC-04)', function () {
        $a = postDimensionValue($this->typeId, ['code' => 'A', 'name' => 'A'])->json('data.id');
        $b = postDimensionValue($this->typeId, ['code' => 'B', 'name' => 'B', 'parent_id' => $a])->json('data.id');
        $c = postDimensionValue($this->typeId, ['code' => 'C', 'name' => 'C', 'parent_id' => $b])->json('data.id');

        foreach ([[$a, $a, 1], [$a, $c, 1]] as [$id, $parent, $version]) {
            $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$id}", ['lock_version' => $version, 'parent_id' => $parent])
                ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        }

        // Moving a leaf elsewhere is fine and audited with before and after (MD-FR-05).
        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$c}", ['lock_version' => 1, 'parent_id' => $a])
            ->assertOk()->assertJsonPath('data.parent_id', $a);

        $audit = AuditEntry::where('event', 'dimension_value.updated')->latest('id')->firstOrFail();

        expect($audit->attribute_changes->all())->toBe(['old' => ['parent_id' => $b], 'attributes' => ['parent_id' => $a]]);
    });

    it('renames and changes the code without touching the type, and answers VERSION_CONFLICT', function () {
        $id = postDimensionValue($this->typeId)->json('data.id');
        postDimensionValue($this->typeId, ['code' => 'BDG', 'name' => 'Bandung']);

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$id}", ['lock_version' => 1, 'name' => 'Jakarta Raya', 'code' => 'JKT2', 'dimension_type_id' => '01J00000000000000000000000'])
            ->assertOk()->assertJsonPath('data.name', 'Jakarta Raya')->assertJsonPath('data.code', 'JKT2')
            ->assertJsonPath('data.dimension_type_id', $this->typeId)->assertJsonPath('data.lock_version', 2);

        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$id}", ['lock_version' => 2, 'code' => 'BDG'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$id}", ['lock_version' => 1, 'name' => 'Stale'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    });

    it('deactivates a leaf, refuses a parent with active children and reactivation under an inactive parent', function () {
        $root = postDimensionValue($this->typeId)->json('data.id');
        $child = postDimensionValue($this->typeId, ['code' => 'JKT-1', 'parent_id' => $root])->json('data.id');

        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$root}/deactivate")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$child}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$root}/deactivate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$child}/reactivate")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$root}/reactivate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$child}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('deletes a value that was never used', function () {
        $id = postDimensionValue($this->typeId)->json('data.id');

        $this->actingAs($this->admin)->deleteJson("{$this->url}/dimension-values/{$id}")->assertNoContent();
        $this->actingAs($this->admin)->getJson("{$this->url}/dimension-values/{$id}")->assertNotFound();

        expect(AuditEntry::where('event', 'dimension_value.deleted')->count())->toBe(1);
    });

    it('never deletes a value that has children or was used, only deactivates it (MD-AC-04)', function () {
        $root = postDimensionValue($this->typeId)->json('data.id');
        $child = postDimensionValue($this->typeId, ['code' => 'JKT-1', 'parent_id' => $root])->json('data.id');

        $this->actingAs($this->admin)->deleteJson("{$this->url}/dimension-values/{$root}")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        useDimensionValue($this->companyId, $this->typeId, $child);

        $this->actingAs($this->admin)->deleteJson("{$this->url}/dimension-values/{$child}")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$child}/deactivate")->assertOk();

        expect(DimensionValue::withoutGlobalScope(CompanyScope::class)->whereKey($child)->exists())->toBeTrue();
    });

    it('lists with filters, pagination and the leaf flag, and keeps companies apart', function () {
        $root = postDimensionValue($this->typeId)->json('data.id');
        postDimensionValue($this->typeId, ['code' => 'JKT-1', 'name' => 'Jakarta Selatan', 'parent_id' => $root]);
        postDimensionValue($this->typeId, ['code' => 'BDG', 'name' => 'Bandung']);

        $list = $this->actingAs($this->admin)->getJson("{$this->url}/dimension-values?filter[dimension_type_id]={$this->typeId}")->assertOk();

        expect(array_column($list->json('data'), 'code'))->toBe(['BDG', 'JKT', 'JKT-1'])
            ->and(array_column($list->json('data'), 'is_leaf'))->toBe([true, false, true])
            ->and($list->json('meta.total'))->toBe(3);

        $this->actingAs($this->admin)->getJson("{$this->url}/dimension-values?filter[parent_id]={$root}")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/dimension-values?filter[search]=selatan")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/dimension-values?per_page=2")->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/dimension-values")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/dimension-values/{$root}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/dimension-values", ['dimension_type_id' => $this->typeId, 'code' => 'X', 'name' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors(['dimension_type_id']);
    });
});

describe('access and company state', function () {
    it('lets a viewer read but not manage dimensions', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');
        $id = postDimensionType()->json('data.id');

        $this->actingAs($viewer)->getJson("{$this->url}/dimension-types")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/dimension-types/{$id}")->assertOk();
        $this->actingAs($viewer)->postJson("{$this->url}/dimension-types", ['key' => 'region', 'label' => 'Region'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $id, 'code' => 'X', 'name' => 'X'])->assertForbidden();
    });

    it('refuses changes once the company is inactive', function () {
        $id = postDimensionType()->json('data.id');

        DB::table('md_companies')->where('id', $this->companyId)->update(['status' => 'inactive']);

        postDimensionValue($id)->assertStatus(409)->assertJsonPath('code', 'COMPANY_READ_ONLY');
        $this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->assertOk();
    });
});

it('keeps dimension rows tied to their own company by composite keys (P-05)', function () {
    $other = User::factory()->create();
    [$otherCompany] = createWorkspaceAs($other, 'Other');
    $mine = DimensionType::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('key', 'project')->firstOrFail();

    expect(fn () => DB::table('md_dimension_values')->insert([
        'id' => (string) Str::ulid(), 'company_id' => $otherCompany, 'dimension_type_id' => $mine->id, 'code' => 'X', 'name' => 'X',
    ]))->toThrow(QueryException::class);
});
