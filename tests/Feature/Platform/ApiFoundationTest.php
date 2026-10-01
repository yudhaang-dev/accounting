<?php

use App\Models\User;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Api\ReportParameters;
use App\Support\Company\CompanyAccessGate;
use App\Support\Company\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ProbeEntryController
{
    public function submit(string $companyId, string $id): JsonResponse
    {
        return response()->json(['data' => ['company' => $companyId, 'id' => $id, 'context' => app(CompanyContext::class)->require()]]);
    }
}

class ProbeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id']];
    }
}

beforeEach(function () {
    Route::prefix('api/v1')->middleware('api')->group(function () {
        Route::get('_probe/conflict', fn () => throw new ApiException(ErrorCode::VersionConflict, errors: ['lock_version' => ['stale']]));
        Route::get('_probe/boom', fn () => throw new RuntimeException('secret detail'));
        Route::get('_probe/throttled', fn () => throw new TooManyRequestsHttpException(30));
        Route::post('_probe/validate', fn (Request $r) => $r->validate(['name' => 'required']));
        Route::get('_probe/item', fn () => new ProbeResource(['id' => 'a']));
        Route::get('_probe/list', fn () => ProbeResource::collection(
            new LengthAwarePaginator([['id' => 'a'], ['id' => 'b']], 5, 2, 1, ['path' => '/api/v1/_probe/list'])
        ));
        Route::get('_probe/report', fn (ReportParameters $p) => ['from' => $p->from?->toDateString(), 'as_of' => $p->as_of?->toDateString()]);
        Route::post('_probe/write', fn () => response()->noContent());

        Route::companyScoped(function () {
            Route::commands('entries', ProbeEntryController::class, ['submit']);
        });
    });
});

function allowCompanies(): void
{
    app()->bind(CompanyAccessGate::class, fn () => new class implements CompanyAccessGate
    {
        public function authorize(Request $request, string $companyId): void
        {
            if ($companyId !== '01j00000000000000000000000') {
                throw new AuthorizationException;
            }
        }
    });
}

it('renders domain errors with code, status and field errors but never data (API-TR-12)', function () {
    $this->getJson('/api/v1/_probe/conflict')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'The resource was changed by someone else. Reload and try again.',
            'code' => 'VERSION_CONFLICT',
            'errors' => ['lock_version' => ['stale']],
        ]);
});

it('translates only the message of an error (API-AC-10)', function () {
    $id = $this->getJson('/api/v1/_probe/conflict', ['Accept-Language' => 'id']);
    $en = $this->getJson('/api/v1/_probe/conflict', ['Accept-Language' => 'en']);

    expect($id->status())->toBe($en->status())
        ->and($id->json('code'))->toBe($en->json('code'))
        ->and(array_keys($id->json('errors')))->toBe(array_keys($en->json('errors')))
        ->and($id->json('message'))->not->toBe($en->json('message'));
});

it('renders validation errors as message plus errors with 422 (API-AC-02)', function () {
    $this->postJson('/api/v1/_probe/validate', [])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['name']])
        ->assertJsonMissingPath('data');
});

it('renders unknown routes, missing auth and rate limits as coded JSON', function () {
    $this->get('/api/v1/does-not-exist')->assertNotFound()->assertJson(['code' => 'NOT_FOUND']);

    $this->get('/api/v1/companies/01j00000000000000000000000/entries/01j00000000000000000000001/submit')
        ->assertStatus(405);

    $this->getJson('/api/v1/_probe/throttled')->assertStatus(429)->assertHeader('Retry-After', '30')->assertJson(['code' => 'TOO_MANY_REQUESTS']);
});

it('hides internal details of server errors unless debugging', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_probe/boom')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'An unexpected error occurred.', 'code' => 'SERVER_ERROR']);
});

it('uses a single data layer and top-level pagination (API-AC-01)', function () {
    $this->getJson('/api/v1/_probe/item')->assertExactJson(['data' => ['id' => 'a']]);

    $list = $this->getJson('/api/v1/_probe/list')->assertOk();

    expect($list->json())->toHaveKeys(['data', 'links', 'meta'])
        ->and($list->json('data'))->toBe([['id' => 'a'], ['id' => 'b']])
        ->and($list->json('meta.total'))->toBe(5)
        ->and($list->json('meta.per_page'))->toBe(2)
        ->and($list->json('data.data'))->toBeNull();
});

it('keeps every error code in the API-CONTRACT table and in both lang files', function () {
    $doc = file_get_contents(base_path('.docs/API-CONTRACT.md'));

    foreach (ErrorCode::cases() as $code) {
        expect(preg_match('/^\|.*`'.$code->value.'`.*\| '.$code->status().' \|/m', $doc))->toBe(1);
        expect(trans("errors.codes.{$code->value}", [], 'en'))->not->toBe("errors.codes.{$code->value}");
        expect(trans("errors.codes.{$code->value}", [], 'id'))->not->toBe("errors.codes.{$code->value}");
    }
});

describe('company scope (API-TR-03, P-05)', function () {
    $company = '01j00000000000000000000000';
    $entry = '01j00000000000000000000001';

    it('requires authentication', function () use ($company, $entry) {
        $this->postJson("/api/v1/companies/$company/entries/$entry/submit")->assertStatus(401)->assertJson(['code' => 'UNAUTHENTICATED']);
    });

    it('denies every company until a gate is bound (fail closed)', function () use ($company, $entry) {
        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/companies/$company/entries/$entry/submit")
            ->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    });

    it('sets the company context for an allowed company and clears it afterwards', function () use ($company, $entry) {
        allowCompanies();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/companies/$company/entries/$entry/submit")
            ->assertOk()
            ->assertJsonPath('data.context', $company)
            ->assertJsonPath('data.id', $entry);

        expect(app(CompanyContext::class)->has())->toBeFalse();
    });

    it('does not let a principal reach another company', function () use ($entry) {
        allowCompanies();
        $other = (string) Str::ulid();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/companies/$other/entries/$entry/submit")
            ->assertForbidden();
    });

    it('does not match malformed ids', function () use ($company) {
        allowCompanies();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/companies/not-a-ulid/entries/$company/submit")->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/companies/$company/entries/not-a-ulid/submit")->assertNotFound();
    });
});

describe('session CSRF and Bearer clients (ADR-0011, API-TR-21, API-TR-22)', function () {
    beforeEach(function () {
        // Laravel skips CSRF checks in the testing environment; pretend to be a real one.
        app()['env'] = 'production';
    });

    it('issues the XSRF cookie from the csrf-cookie endpoint', function () {
        $this->get('/api/v1/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
    });

    it('rejects cookie-session writes without a CSRF token', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/_probe/write')
            ->assertStatus(419)
            ->assertJson(['code' => 'CSRF_TOKEN_MISMATCH']);
    });

    it('accepts cookie-session writes with a valid CSRF token', function () {
        $this->withSession(['_token' => 'abc'])
            ->actingAs(User::factory()->create())
            ->postJson('/api/v1/_probe/write', [], ['X-CSRF-TOKEN' => 'abc'])
            ->assertNoContent();
    });

    it('lets Bearer clients write without cookies, session or CSRF', function () {
        $this->postJson('/api/v1/_probe/write', [], ['Authorization' => 'Bearer opaque-token'])
            ->assertNoContent()
            ->assertCookieMissing('laravel_session')
            ->assertCookieMissing('XSRF-TOKEN');
    });
});

it('validates report parameters (API-TR-09)', function (array $query, int $status) {
    $this->getJson('/api/v1/_probe/report?'.http_build_query($query))->assertStatus($status);
})->with([
    'period' => [['from' => '2026-01-01', 'to' => '2026-01-31'], 200],
    'as of' => [['as_of' => '2026-01-31'], 200],
    'none' => [[], 200],
    'from without to' => [['from' => '2026-01-01'], 422],
    'to before from' => [['from' => '2026-02-01', 'to' => '2026-01-01'], 422],
    'as_of with period' => [['as_of' => '2026-01-31', 'from' => '2026-01-01', 'to' => '2026-01-31'], 422],
    'bad date' => [['as_of' => '31/01/2026'], 422],
    'bad dimension id' => [['dimensions' => ['department' => 'x']], 422],
    'bad type key' => [['transaction_type_keys' => ['Bad Key']], 422],
]);

it('parses report parameters into typed values', function () {
    $this->getJson('/api/v1/_probe/report?as_of=2026-01-31')->assertExactJson(['from' => null, 'as_of' => '2026-01-31']);
});

it('answers CORS preflight only for configured SPA origins (API-TR-02)', function () {
    config(['cors.allowed_origins' => ['https://app.example.com']]);

    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/_probe/write', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'idempotency-key,x-xsrf-token',
    ]);

    $preflight('https://app.example.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    expect($preflight('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example.com');
});
