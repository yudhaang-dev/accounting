<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Platform\Api\ApiException;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
    $this->sets = app(DimensionSets::class);

    $this->branch = dimType('branch', ['is_hierarchical' => true]);
    $this->region = dimType('region');

    $this->jakarta = dimValue($this->branch, 'JKT', 'Jakarta');
    $this->jaksel = dimValue($this->branch, 'JKT-S', 'Jakarta Selatan', ['parent_id' => $this->jakarta]);
    $this->kebayoran = dimValue($this->branch, 'JKT-S1', 'Kebayoran', ['parent_id' => $this->jaksel]);
    $this->bandung = dimValue($this->branch, 'BDG', 'Bandung');
    $this->west = dimValue($this->region, 'W', 'West');
});

function dimType(string $key, array $overrides = []): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-types', array_merge(['key' => $key, 'label' => ucfirst($key)], $overrides))
        ->assertCreated()->json('data.id');
}

function dimValue(string $typeId, string $code, string $name, array $overrides = []): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', array_merge(['dimension_type_id' => $typeId, 'code' => $code, 'name' => $name], $overrides))
        ->assertCreated()->json('data.id');
}

function resolveFailure(array $dimensions, string $date = '2026-03-15'): ApiException
{
    try {
        test()->sets->resolve(test()->companyId, $dimensions, $date);
    } catch (ApiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the combination to be refused.');
}

describe('resolving a combination (MD-FR-04, MD-AC-01)', function () {
    it('gives the same set for the same combination in any order and the empty set for none', function () {
        $first = $this->sets->resolve($this->companyId, ['branch' => $this->bandung, 'region' => $this->west], '2026-03-15');
        $second = $this->sets->resolve($this->companyId, ['region' => $this->west, 'branch' => $this->bandung], '2026-03-15');

        expect($second)->toBe($first)
            ->and($this->sets->resolve($this->companyId, ['branch' => $this->jakarta, 'region' => $this->west], '2026-03-15'))->not->toBe($first)
            ->and($this->sets->resolve($this->companyId, ['branch' => $this->bandung], '2026-03-15'))->not->toBe($first)
            ->and($this->sets->resolve($this->companyId, [], '2026-03-15'))->toBe($this->sets->emptySet($this->companyId))
            ->and($this->sets->resolve($this->companyId, ['branch' => null, 'region' => null], '2026-03-15'))->toBe($this->sets->emptySet($this->companyId))
            ->and(DB::table('md_dimension_set_items')->where('dimension_set_id', $first)->count())->toBe(2)
            ->and(DB::table('md_dimension_sets')->where('company_id', $this->companyId)->count())->toBe(4);
    });

    it('resolves a list of value IDs to the same set and refuses two values of one type', function () {
        $set = $this->sets->resolveValues($this->companyId, [$this->west, $this->bandung], '2026-03-15');

        expect($set)->toBe($this->sets->resolve($this->companyId, ['branch' => $this->bandung, 'region' => $this->west], '2026-03-15'));

        try {
            $this->sets->resolveValues($this->companyId, [$this->bandung, $this->jakarta], '2026-03-15');
            $failure = null;
        } catch (ApiException $exception) {
            $failure = $exception;
        }

        expect($failure?->errorCode->value)->toBe('DIMENSION_INVALID')
            ->and(array_keys($failure->errors))->toBe(['dimensions.branch']);
    });

    it('describes sets as type key to value ID', function () {
        $set = $this->sets->resolve($this->companyId, ['branch' => $this->bandung, 'region' => $this->west], '2026-03-15');

        expect($this->sets->describe($this->companyId, [$set, $this->sets->emptySet($this->companyId), '01J00000000000000000000000']))
            ->toBe([$set => ['branch' => $this->bandung, 'region' => $this->west], $this->sets->emptySet($this->companyId) => []]);
    });

    it('refuses unknown types, values of another type and values of another company', function () {
        expect(resolveFailure(['unknown' => $this->bandung])->errors)->toHaveKey('dimensions.unknown')
            ->and(resolveFailure(['branch' => $this->west])->errors)->toHaveKey('dimensions.branch')
            ->and(resolveFailure(['branch' => '01J00000000000000000000000'])->errors)->toHaveKey('dimensions.branch');

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/dimension-types", ['key' => 'branch', 'label' => 'Branch']);

        expect(fn () => $this->sets->resolve($otherCompany, ['branch' => $this->bandung], '2026-03-15'))->toThrow(ApiException::class);
    });
});

describe('what a new use is refused for (MD-AC-02)', function () {
    it('refuses an inactive value, naming the dimension, but keeps the historical set readable', function () {
        $set = $this->sets->resolve($this->companyId, ['branch' => $this->bandung], '2026-03-15');

        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$this->bandung}/deactivate")->assertOk();

        $failure = resolveFailure(['branch' => $this->bandung]);

        expect($failure->errorCode->value)->toBe('DIMENSION_INVALID')
            ->and($failure->errors['dimensions.branch'][0])->toContain('Branch')->toContain('BDG')
            ->and($this->sets->describe($this->companyId, [$set]))->toBe([$set => ['branch' => $this->bandung]]);
    });

    it('refuses a value outside its validity period and accepts both boundaries', function () {
        $project = dimValue($this->region, 'Q1', 'Q1 2026', ['valid_from' => '2026-01-01', 'valid_to' => '2026-03-31']);

        expect(resolveFailure(['region' => $project], '2025-12-31')->errors['dimensions.region'][0])->toContain('Q1')->toContain('2025-12-31')
            ->and(resolveFailure(['region' => $project], '2026-04-01')->errors)->toHaveKey('dimensions.region')
            ->and($this->sets->resolve($this->companyId, ['region' => $project], '2026-01-01'))->toBeString()
            ->and($this->sets->resolve($this->companyId, ['region' => $project], '2026-03-31'))->toBeString();
    });

    it('refuses an inactive type', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$this->region}/deactivate")->assertOk();

        expect(resolveFailure(['region' => $this->west])->errors['dimensions.region'][0])->toContain('Region');
    });

    it('refuses a value with children where the type is leaf-only, and accepts its leaf', function () {
        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-types/{$this->branch}", ['lock_version' => 1, 'leaf_only' => true])->assertOk();

        expect(resolveFailure(['branch' => $this->jaksel])->errors['dimensions.branch'][0])->toContain('JKT-S')
            ->and(resolveFailure(['branch' => $this->jakarta])->errors)->toHaveKey('dimensions.branch')
            ->and($this->sets->resolve($this->companyId, ['branch' => $this->kebayoran], '2026-03-15'))->toBeString()
            ->and($this->sets->resolve($this->companyId, ['branch' => $this->bandung], '2026-03-15'))->toBeString();
    });

    it('reports every offending dimension at once', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$this->bandung}/deactivate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$this->west}/deactivate")->assertOk();

        expect(array_keys(resolveFailure(['branch' => $this->bandung, 'region' => $this->west])->errors))->toBe(['dimensions.branch', 'dimensions.region']);
    });
});

describe('hierarchy queries (MD-FR-05)', function () {
    it('lists descendants at any depth and ancestors nearest first', function () {
        expect($this->sets->descendants($this->companyId, $this->jakarta))->toBe([$this->jaksel, $this->kebayoran])
            ->and($this->sets->descendants($this->companyId, $this->kebayoran))->toBe([])
            ->and($this->sets->ancestors($this->companyId, $this->kebayoran))->toBe([$this->jaksel, $this->jakarta])
            ->and($this->sets->ancestors($this->companyId, $this->jakarta))->toBe([]);
    });

    it('follows the current hierarchy after a value is moved', function () {
        $this->actingAs($this->admin)->patchJson("{$this->url}/dimension-values/{$this->jaksel}", ['lock_version' => 1, 'parent_id' => $this->bandung])->assertOk();

        expect($this->sets->descendants($this->companyId, $this->jakarta))->toBe([])
            ->and($this->sets->descendants($this->companyId, $this->bandung))->toBe([$this->jaksel, $this->kebayoran]);
    });

    it('finds the sets of a value and everything below it', function () {
        $leaf = $this->sets->resolve($this->companyId, ['branch' => $this->kebayoran], '2026-03-15');
        $middle = $this->sets->resolve($this->companyId, ['branch' => $this->jaksel, 'region' => $this->west], '2026-03-15');
        $other = $this->sets->resolve($this->companyId, ['branch' => $this->bandung], '2026-03-15');

        expect($this->sets->setIdsMatching($this->companyId, ['branch' => $this->jakarta]))->toEqualCanonicalizing([$leaf, $middle])
            ->and($this->sets->setIdsMatching($this->companyId, ['branch' => $this->kebayoran]))->toBe([$leaf])
            ->and($this->sets->setIdsMatching($this->companyId, ['branch' => $this->jakarta, 'region' => $this->west]))->toBe([$middle])
            ->and($this->sets->setIdsMatching($this->companyId, ['branch' => $this->bandung]))->toBe([$other]);

        expect(fn () => $this->sets->setIdsMatching($this->companyId, ['branch' => $this->west]))->toThrow(ApiException::class);
    });
});

describe('dimensions on entries, journals and the trial balance', function () {
    beforeEach(function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();

        $this->bank = (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '1112')->value('id');
        $this->sales = (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('code', '4110')->value('id');
    });

    function dimensionalEntry(array $dimensions, string $amount = '1000.00', string $date = '2026-03-15'): array
    {
        return [
            'transaction_type_key' => 'general_journal',
            'accounting_date' => $date,
            'description' => 'Penjualan',
            'lines' => [
                ['account_id' => test()->bank, 'debit' => $amount, 'dimensions' => $dimensions],
                ['account_id' => test()->sales, 'credit' => $amount, 'dimensions' => $dimensions],
            ],
        ];
    }

    function postDimensionalEntry(array $payload): string
    {
        $id = test()->actingAs(test()->admin)->postJson(test()->url.'/entries', $payload)->assertCreated()->json('data.id');

        foreach (['submit', 'approve', 'post'] as $action) {
            test()->actingAs(test()->admin)->postJson(test()->url."/entries/{$id}/{$action}")->assertOk();
        }

        return $id;
    }

    it('keeps the dimensions on a draft and posts them as a dimension set', function () {
        $id = postDimensionalEntry(dimensionalEntry(['branch' => $this->kebayoran, 'region' => $this->west]));

        $entry = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$id}")->assertOk();
        $journal = $this->actingAs($this->admin)->getJson("{$this->url}/journals/".$entry->json('data.journal_id'))->assertOk();

        expect($entry->json('data.lines.0.dimensions'))->toBe(['branch' => $this->kebayoran, 'region' => $this->west])
            ->and($journal->json('data.lines.0.dimensions'))->toBe(['branch' => $this->kebayoran, 'region' => $this->west])
            ->and($journal->json('data.lines.0.dimension_set_id'))->toBe($this->sets->resolve($this->companyId, ['branch' => $this->kebayoran, 'region' => $this->west], '2026-03-15'));
    });

    it('answers an empty object for lines without dimensions', function () {
        $id = postDimensionalEntry(dimensionalEntry([]));
        $journalId = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$id}")->json('data.journal_id');

        $this->actingAs($this->admin)->getJson("{$this->url}/journals/{$journalId}")->assertOk()->assertJsonPath('data.lines.0.dimensions', []);
        expect($this->actingAs($this->admin)->get("{$this->url}/journals/{$journalId}")->getContent())->toContain('"dimensions":{}');
    });

    it('refuses a bad dimension on submit with DIMENSION_INVALID naming the line and dimension', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values/{$this->bandung}/deactivate")->assertOk();

        $id = $this->actingAs($this->admin)->postJson("{$this->url}/entries", dimensionalEntry(['branch' => $this->bandung]))->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("{$this->url}/entries/{$id}/submit")
            ->assertUnprocessable()->assertJsonPath('code', 'DIMENSION_INVALID')
            ->assertJsonValidationErrors(['lines.0.dimensions.branch', 'lines.1.dimensions.branch']);
    });

    it('validates a value on the accounting date of the entry', function () {
        $quarter = dimValue($this->region, 'Q1', 'Q1 2026', ['valid_to' => '2026-03-31']);

        postDimensionalEntry(dimensionalEntry(['region' => $quarter], date: '2026-03-31'));

        $id = $this->actingAs($this->admin)->postJson("{$this->url}/entries", dimensionalEntry(['region' => $quarter], date: '2026-04-01'))->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("{$this->url}/entries/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'DIMENSION_INVALID');
    });

    it('filters the trial balance by a value and rolls up its descendants', function () {
        postDimensionalEntry(dimensionalEntry(['branch' => $this->kebayoran], '1000.00'));
        postDimensionalEntry(dimensionalEntry(['branch' => $this->jaksel], '200.00'));
        postDimensionalEntry(dimensionalEntry(['branch' => $this->bandung], '30.00'));
        postDimensionalEntry(dimensionalEntry([], '4.00'));

        $report = fn (array $query) => $this->actingAs($this->admin)->getJson("{$this->url}/reports/trial-balance?".http_build_query(['from' => '2026-01-01', 'to' => '2026-12-31', ...$query]));
        $bankDebit = fn ($response) => collect($response->json('data.rows'))->firstWhere('code', '1112')['debit'];

        expect($bankDebit($report([])))->toBe('1234.00')
            ->and($bankDebit($report(['dimensions' => ['branch' => $this->jakarta]])))->toBe('1200.00')
            ->and($bankDebit($report(['dimensions' => ['branch' => $this->jaksel]])))->toBe('1200.00')
            ->and($bankDebit($report(['dimensions' => ['branch' => $this->kebayoran]])))->toBe('1000.00')
            ->and($bankDebit($report(['dimensions' => ['branch' => $this->bandung]])))->toBe('30.00');

        $filtered = $report(['dimensions' => ['branch' => $this->jakarta]])->assertOk();

        expect($filtered->json('data.parameters.dimensions'))->toBe(['branch' => $this->jakarta])
            ->and($filtered->json('data.balanced'))->toBeTrue();

        $report(['dimensions' => ['branch' => $this->west]])->assertUnprocessable()->assertJsonPath('code', 'DIMENSION_INVALID');
        $report(['dimensions' => ['nope' => $this->bandung]])->assertUnprocessable()->assertJsonPath('code', 'DIMENSION_INVALID');
    });

    it('filters the journal drill-down by dimension and shows the lines dimensions', function () {
        postDimensionalEntry(dimensionalEntry(['branch' => $this->kebayoran], '1000.00'));
        postDimensionalEntry(dimensionalEntry(['branch' => $this->bandung], '30.00'));
        postDimensionalEntry(dimensionalEntry([], '4.00'));

        $list = $this->actingAs($this->admin)->getJson("{$this->url}/journals?".http_build_query(['filter' => ['dimensions' => ['branch' => $this->jakarta]]]))->assertOk();

        expect($list->json('data'))->toHaveCount(1)
            ->and($list->json('data.0.lines.0.dimensions'))->toBe(['branch' => $this->kebayoran]);

        $this->actingAs($this->admin)->getJson("{$this->url}/journals?".http_build_query(['filter' => ['account_id' => $this->bank, 'dimensions' => ['branch' => $this->bandung]]]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/journals?".http_build_query(['filter' => ['dimensions' => ['branch' => $this->west]]]))
            ->assertUnprocessable()->assertJsonPath('code', 'DIMENSION_INVALID');
    });
});
