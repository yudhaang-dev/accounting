<?php

use App\Models\User;
use App\Modules\MasterData\Application\RecordExchangeRate;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Modules\MasterData\Domain\ExchangeRate;
use App\Modules\MasterData\Domain\RateSource;
use App\Platform\Api\ApiException;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    foreach (['USD', 'EUR'] as $code) {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => $code])->assertCreated();
    }

    $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'central_bank', 'label' => 'Central bank mid rate'])
        ->assertCreated()->json('data.id');
});

function putRate(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/exchange-rates', array_merge([
        'rate_type' => 'central_bank',
        'currency' => 'USD',
        'effective_date' => '2026-03-10',
        'rate' => '16250.50',
    ], $overrides));
}

function lookupRate(array $query): TestResponse
{
    return test()->actingAs(test()->admin)->getJson(test()->url.'/exchange-rates/lookup?'.http_build_query(array_merge(['rate_type' => 'central_bank', 'currency' => 'USD', 'date' => '2026-03-10'], $query)));
}

describe('rate types (MD-FR-07)', function () {
    it('creates, reads, updates and deactivates a rate type', function () {
        $id = $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'tax', 'label' => 'Tax rate', 'max_age_days' => 7])
            ->assertCreated()->assertJson(['data' => ['key' => 'tax', 'label' => 'Tax rate', 'max_age_days' => 7, 'status' => 'active', 'lock_version' => 1]])->json('data.id');

        $this->actingAs($this->admin)->patchJson("{$this->url}/rate-types/{$id}", ['lock_version' => 1, 'label' => 'Kurs pajak', 'max_age_days' => null, 'key' => 'other'])
            ->assertOk()->assertJsonPath('data.key', 'tax')->assertJsonPath('data.label', 'Kurs pajak')->assertJsonPath('data.max_age_days', null)->assertJsonPath('data.lock_version', 2);
        $this->actingAs($this->admin)->patchJson("{$this->url}/rate-types/{$id}", ['lock_version' => 1, 'label' => 'Stale'])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types/{$id}/deactivate")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

        $this->actingAs($this->admin)->getJson("{$this->url}/rate-types")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.key', 'central_bank');
        $this->actingAs($this->admin)->getJson("{$this->url}/rate-types?filter[status]=inactive")->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/rate-types/{$id}")->assertOk()->assertJsonPath('data.key', 'tax');
    });

    it('validates the key and keeps it unique per company', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'Bad Key', 'label' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['key']);
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'central_bank', 'label' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['key']);
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'x', 'label' => 'X', 'max_age_days' => -1])->assertUnprocessable()->assertJsonValidationErrors(['max_age_days']);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/rate-types", ['key' => 'central_bank', 'label' => 'Theirs'])->assertCreated();
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/rate-types/{$this->typeId}")->assertNotFound();
    });
});

describe('recording rates (MD-FR-07)', function () {
    it('records a rate with source, actor and a normalized decimal string', function () {
        putRate()->assertCreated()->assertJson(['data' => [
            'rate_type' => 'central_bank', 'rate_type_id' => $this->typeId, 'currency' => 'USD', 'effective_date' => '2026-03-10',
            'rate' => '16250.50', 'version' => 1, 'source' => 'manual', 'actor_type' => (new User)->getMorphClass(), 'actor_id' => (string) $this->admin->id,
        ]]);

        expect(putRate(['currency' => 'EUR', 'rate' => '17000'])->assertCreated()->json('data.rate'))->toBe('17000.00')
            ->and(putRate(['currency' => 'EUR', 'effective_date' => '2026-03-11', 'rate' => '0.000064000000'])->assertCreated()->json('data.rate'))->toBe('0.000064')
            ->and(putRate(['currency' => 'EUR', 'effective_date' => '2026-03-12', 'rate' => '123456789012.123456789012'])->assertCreated()->json('data.rate'))->toBe('123456789012.123456789012')
            ->and(AuditEntry::where('event', 'exchange_rate.recorded')->where('company_id', $this->companyId)->count())->toBe(4);
    });

    it('refuses malformed input', function () {
        foreach (['0', '0.000', '-5', '1e3', 'abc', '1.1234567890123', '1234567890123', ''] as $rate) {
            putRate(['rate' => $rate])->assertUnprocessable()->assertJsonValidationErrors(['rate']);
        }

        putRate(['effective_date' => '10-03-2026'])->assertUnprocessable()->assertJsonValidationErrors(['effective_date']);
        putRate(['currency' => 'usd'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        putRate(['rate_type' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors(['rate_type']);
    });

    it('refuses the functional currency, currencies not activated and inactive rate types', function () {
        putRate(['currency' => 'IDR'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        putRate(['currency' => 'JPY'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);

        $this->actingAs($this->admin)->postJson("{$this->url}/currencies/EUR/deactivate")->assertOk();
        putRate(['currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);

        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types/{$this->typeId}/deactivate")->assertOk();
        putRate()->assertUnprocessable()->assertJsonValidationErrors(['rate_type']);
    });

    it('records a correction as a new version and keeps history', function () {
        $first = putRate()->assertCreated()->json('data.id');
        $second = putRate(['rate' => '16300'])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.rate', '16300.00')->json('data.id');

        $current = $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates")->assertOk();
        $all = $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates?filter[include_superseded]=1")->assertOk();

        expect(array_column($current->json('data'), 'id'))->toBe([$second])
            ->and(array_column($all->json('data'), 'version'))->toBe([2, 1])
            ->and(DB::table('md_exchange_rates')->where('id', $first)->value('rate'))->toBe('16250.500000000000');

        $audit = AuditEntry::where('event', 'exchange_rate.corrected')->firstOrFail();

        expect($audit->attribute_changes->all())->toEqual(['old' => ['rate' => '16250.50', 'version' => 1], 'attributes' => ['rate' => '16300.00', 'source' => 'manual', 'version' => 2]]);
    });

    it('answers 200 with the existing version when the same rate is sent again', function () {
        $id = putRate()->assertCreated()->json('data.id');

        putRate(['rate' => '16250.5'])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.version', 1);

        expect(DB::table('md_exchange_rates')->count())->toBe(1);
    });

    it('never lets a stored rate change or disappear', function () {
        $id = putRate()->assertCreated()->json('data.id');

        // Each statement in its own savepoint: a failed one aborts the surrounding transaction otherwise.
        expect(fn () => ExchangeRate::withoutGlobalScope(CompanyScope::class)->findOrFail($id)->update(['version' => 9]))->toThrow(LogicException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('md_exchange_rates')->where('id', $id)->update(['rate' => '1'])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('md_exchange_rates')->where('id', $id)->delete()))->toThrow(QueryException::class);
    });

    it('lists with filters and pagination, and keeps companies apart', function () {
        putRate(['effective_date' => '2026-03-10'])->assertCreated();
        putRate(['effective_date' => '2026-03-11'])->assertCreated();
        putRate(['effective_date' => '2026-03-11', 'currency' => 'EUR', 'rate' => '17500'])->assertCreated();

        $list = $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates")->assertOk();

        expect(array_column($list->json('data'), 'effective_date'))->toBe(['2026-03-11', '2026-03-11', '2026-03-10'])
            ->and($list->json('meta.total'))->toBe(3);

        $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates?filter[currency]=EUR")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates?filter[from]=2026-03-11&filter[to]=2026-03-11")->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates?filter[rate_type]=central_bank&per_page=2")->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
        $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates?filter[rate_type]=nope")->assertJsonCount(0, 'data');

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/exchange-rates")->assertJsonCount(0, 'data');
    });

    it('attributes a rate sent by something other than a user to the API', function () {
        Auth::setUser(new GenericUser(['id' => 'client-1']));

        expect(RecordExchangeRate::sourceOfCurrentActor())->toBe(RateSource::Api);

        Auth::setUser($this->admin);

        expect(RecordExchangeRate::sourceOfCurrentActor())->toBe(RateSource::Manual);
    });
});

describe('looking a rate up (MD-FR-08, MD-AC-06)', function () {
    beforeEach(function () {
        putRate(['effective_date' => '2026-03-02', 'rate' => '16000'])->assertCreated();
        putRate(['effective_date' => '2026-03-10', 'rate' => '16250.50'])->assertCreated();
    });

    it('uses the latest rate on or before the date', function () {
        lookupRate(['date' => '2026-03-10'])->assertOk()->assertJson(['data' => ['rate' => '16250.50', 'effective_date' => '2026-03-10', 'requested_date' => '2026-03-10', 'version' => 1]]);
        lookupRate(['date' => '2026-03-15'])->assertOk()->assertJsonPath('data.rate', '16250.50')->assertJsonPath('data.effective_date', '2026-03-10');
        lookupRate(['date' => '2026-03-09'])->assertOk()->assertJsonPath('data.rate', '16000.00')->assertJsonPath('data.effective_date', '2026-03-02');
    });

    it('uses the latest version after a correction, while the old version stays stored', function () {
        putRate(['effective_date' => '2026-03-10', 'rate' => '16400'])->assertCreated();

        lookupRate(['date' => '2026-03-12'])->assertOk()->assertJsonPath('data.rate', '16400.00')->assertJsonPath('data.version', 2);

        expect(DB::table('md_exchange_rates')->where('effective_date', '2026-03-10')->orderBy('version')->pluck('rate')->all())->toBe(['16250.500000000000', '16400.000000000000']);
    });

    it('answers EXCHANGE_RATE_NOT_FOUND instead of guessing', function () {
        lookupRate(['date' => '2026-03-01'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        // No fallback to 1, to another currency (no cross or inverse rate) or to another rate type.
        lookupRate(['currency' => 'EUR'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        lookupRate(['currency' => 'IDR'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        lookupRate(['rate_type' => 'missing'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');

        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types", ['key' => 'tax', 'label' => 'Tax'])->assertCreated();
        lookupRate(['rate_type' => 'tax'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
    });

    it('names the currency, rate type and date in the error', function () {
        $message = lookupRate(['date' => '2026-03-01', 'currency' => 'EUR'])->json('message');

        expect($message)->toContain('Central bank mid rate')->toContain('EUR')->toContain('2026-03-01');
    });

    it('refuses a rate older than the age limit of the rate type and accepts the limit itself', function () {
        $this->actingAs($this->admin)->patchJson("{$this->url}/rate-types/{$this->typeId}", ['lock_version' => 1, 'max_age_days' => 7])->assertOk();

        lookupRate(['date' => '2026-03-17'])->assertOk()->assertJsonPath('data.effective_date', '2026-03-10');
        lookupRate(['date' => '2026-03-18'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');

        expect(lookupRate(['date' => '2026-03-18'])->json('message'))->toContain('7');

        $this->actingAs($this->admin)->patchJson("{$this->url}/rate-types/{$this->typeId}", ['lock_version' => 2, 'max_age_days' => null])->assertOk();
        lookupRate(['date' => '2026-12-31'])->assertOk()->assertJsonPath('data.effective_date', '2026-03-10');
    });

    it('keeps rates readable after their rate type is deactivated', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/rate-types/{$this->typeId}/deactivate")->assertOk();

        lookupRate(['date' => '2026-03-12'])->assertOk()->assertJsonPath('data.rate', '16250.50');
    });

    it('validates the parameters', function () {
        lookupRate(['date' => '10/03/2026'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        lookupRate(['currency' => 'us'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates/lookup")->assertUnprocessable()->assertJsonValidationErrors(['rate_type', 'currency', 'date']);
    });

    it('is available to other modules through the ExchangeRates contract', function () {
        $found = app(ExchangeRates::class)->find($this->companyId, 'central_bank', 'USD', '2026-03-12');

        expect($found->rate)->toBe('16250.50')->and($found->effectiveDate)->toBe('2026-03-10')->and($found->version)->toBe(1)->and($found->rateTypeKey)->toBe('central_bank');

        expect(fn () => app(ExchangeRates::class)->find($this->companyId, 'central_bank', 'USD', '2026-01-01'))->toThrow(ApiException::class);

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        expect(fn () => app(ExchangeRates::class)->find($otherCompany, 'central_bank', 'USD', '2026-03-12'))->toThrow(ApiException::class);
    });
});

describe('importing rates from CSV (MD-FR-07)', function () {
    function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rates.csv', $content);
    }

    it('imports a file with columns in any order and a BOM, recording the source as csv', function () {
        $csv = "\xEF\xBB\xBFcurrency,rate,Rate_Type,effective_date\r\nUSD,16250.50,central_bank,2026-03-10\r\nEUR,17500,central_bank,2026-03-10\r\n\r\n";

        $this->actingAs($this->admin)->post("{$this->url}/exchange-rates/import", ['file' => csvFile($csv)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.created', 2)->assertJsonPath('data.unchanged', 0);

        expect(DB::table('md_exchange_rates')->pluck('source')->unique()->all())->toBe(['csv'])
            ->and(lookupRate(['currency' => 'EUR', 'date' => '2026-03-10'])->json('data.rate'))->toBe('17500.00');
    });

    it('accepts a text/csv body, and counts unchanged rows and corrections', function () {
        putRate()->assertCreated();

        $csv = "rate_type,currency,effective_date,rate\ncentral_bank,USD,2026-03-10,16250.5\ncentral_bank,EUR,2026-03-10,17500\n";
        $this->call('POST', "{$this->url}/exchange-rates/import", server: ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], content: $csv, parameters: [])
            ->assertCreated()->assertJsonPath('data.created', 1)->assertJsonPath('data.unchanged', 1);

        $corrected = "rate_type,currency,effective_date,rate\ncentral_bank,USD,2026-03-10,16999\n";
        $this->call('POST', "{$this->url}/exchange-rates/import", server: ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], content: $corrected, parameters: [])
            ->assertCreated()->assertJsonPath('data.created', 1);

        expect(lookupRate(['date' => '2026-03-10'])->json('data.version'))->toBe(2);
    });

    it('rejects the whole file when any row is invalid and lists every problem by line', function () {
        $csv = "rate_type,currency,effective_date,rate\n"
            ."central_bank,USD,2026-03-10,16250.50\n"
            ."central_bank,JPY,2026-03-10,110\n"
            ."central_bank,EUR,2026-03-10,0\n"
            ."missing,EUR,2026-03-10,17000\n"
            ."central_bank,USD,2026-03-10,16300\n";

        $response = $this->actingAs($this->admin)->post("{$this->url}/exchange-rates/import", ['file' => csvFile($csv)], ['Accept' => 'application/json'])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(['rows.3.currency', 'rows.4.rate', 'rows.5.rate_type', 'rows.6'])
            ->and(DB::table('md_exchange_rates')->count())->toBe(0);
    });

    it('refuses a missing header, an empty file, a missing file and too many rows', function () {
        $post = fn (string $content) => $this->actingAs($this->admin)->post("{$this->url}/exchange-rates/import", ['file' => csvFile($content)], ['Accept' => 'application/json']);

        $post("rate_type,currency,rate\ncentral_bank,USD,1\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $post("rate_type,currency,effective_date,rate\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $post('')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->actingAs($this->admin)->postJson("{$this->url}/exchange-rates/import", [])->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $rows = implode("\n", array_fill(0, 1001, 'central_bank,USD,2026-03-10,1'));
        $post("rate_type,currency,effective_date,rate\n{$rows}\n")->assertUnprocessable()->assertJsonValidationErrors(['file']);
    });
});

describe('access', function () {
    it('lets a viewer read rates and rate types but not record, import or change them', function () {
        putRate()->assertCreated();
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/rate-types")->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/exchange-rates")->assertOk();
        lookupRateAs($viewer)->assertOk();

        $this->actingAs($viewer)->postJson("{$this->url}/rate-types", ['key' => 'x', 'label' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/exchange-rates", ['rate_type' => 'central_bank', 'currency' => 'USD', 'effective_date' => '2026-03-10', 'rate' => '1'])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/exchange-rates/import", [])->assertForbidden();
    });

    it('refuses recording once the company is inactive', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        putRate()->assertStatus(409)->assertJsonPath('code', 'COMPANY_READ_ONLY');
        $this->actingAs($this->admin)->getJson("{$this->url}/exchange-rates")->assertOk();
    });

    function lookupRateAs(User $user): TestResponse
    {
        return test()->actingAs($user)->getJson(test()->url.'/exchange-rates/lookup?rate_type=central_bank&currency=USD&date=2026-03-10');
    }
});
