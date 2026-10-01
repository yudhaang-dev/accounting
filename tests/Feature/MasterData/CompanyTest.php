<?php

use App\Models\User;
use App\Modules\MasterData\Domain\Company;
use App\Orchestration\Company\ActivateCompany;
use App\Orchestration\Company\ActivationRequirement;
use App\Platform\Audit\AuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

class CompanyWriteProbeController
{
    public function store(string $companyId): JsonResponse
    {
        return response()->json(['data' => ['company' => $companyId]], 201);
    }
}

class FakeActivationRequirement implements ActivationRequirement
{
    public static bool $met = false;

    public function key(): string
    {
        return 'chart_of_accounts';
    }

    public function message(): string
    {
        return 'The chart of accounts is not confirmed.';
    }

    public function isMet(string $companyId): bool
    {
        return self::$met;
    }
}

beforeEach(function () {
    Route::prefix('api/v1')->middleware('api')->group(function () {
        Route::companyScoped(function () {
            Route::post('_probe/things', [CompanyWriteProbeController::class, 'store']);
            Route::get('_probe/things', fn (string $companyId) => response()->json(['data' => []]));
        });
    });

    $this->admin = User::factory()->create();
    $response = $this->actingAs($this->admin)->postJson('/api/v1/workspaces', [
        'workspace_name' => 'Acme',
        'company_name' => 'PT Acme',
        'functional_currency' => 'IDR',
    ])->assertCreated();

    $this->companyId = $response->json('data.company_id');
    $this->url = "/api/v1/companies/{$this->companyId}";

    // Activation needs a confirmed chart of accounts (GL-FR-01, X-AC-03).
    confirmChartOfAccounts($this->admin, $this->companyId);
});

describe('GET /companies/{companyId} (MD-FR-01)', function () {
    it('returns the profile with status and lock_version', function () {
        $this->actingAs($this->admin)->getJson($this->url)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $this->companyId,
                'name' => 'PT Acme',
                'legal_name' => null,
                'tax_id' => null,
                'functional_currency' => 'IDR',
                'timezone' => 'Asia/Jakarta',
                'default_locale' => config('app.locale'),
                'status' => 'setup',
                'lock_version' => 1,
            ]]);
    });

    it('needs company.read and membership', function () {
        $noRole = memberWithRoles($this->companyId);

        $this->actingAs($noRole)->getJson($this->url)->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
        $this->actingAs(User::factory()->create())->getJson($this->url)->assertForbidden();
        $this->actingAs(memberWithRoles($this->companyId, 'viewer'))->getJson($this->url)->assertOk();
    });
});

describe('PATCH /companies/{companyId} (MD-FR-01)', function () {
    it('changes the profile, bumps lock_version and audits before/after', function () {
        $this->actingAs($this->admin)->patchJson($this->url, [
            'lock_version' => 1,
            'name' => 'PT Acme Tbk',
            'legal_name' => 'PT Acme Indonesia Tbk',
            'tax_id' => '01.234.567.8-901.000',
            'timezone' => 'Asia/Makassar',
            'default_locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'PT Acme Tbk')
            ->assertJsonPath('data.tax_id', '01.234.567.8-901.000')
            ->assertJsonPath('data.timezone', 'Asia/Makassar')
            ->assertJsonPath('data.lock_version', 2);

        $audit = AuditEntry::where('event', 'company.updated')->where('company_id', $this->companyId)->sole();

        expect($audit->attribute_changes['old']['name'])->toBe('PT Acme')
            ->and($audit->attribute_changes['attributes']['name'])->toBe('PT Acme Tbk');
    });

    it('answers VERSION_CONFLICT for a stale lock_version and changes nothing', function () {
        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => 1, 'name' => 'First'])->assertOk();

        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => 1, 'name' => 'Second'])
            ->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);

        expect(Company::find($this->companyId)->name)->toBe('First');
    });

    it('validates the payload', function () {
        $this->actingAs($this->admin)
            ->patchJson($this->url, ['name' => '', 'functional_currency' => 'rupiah', 'timezone' => 'Mars/Base', 'default_locale' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lock_version', 'name', 'functional_currency', 'timezone', 'default_locale']);
    });

    it('needs company.update', function () {
        $this->actingAs(memberWithRoles($this->companyId, 'viewer'))
            ->patchJson($this->url, ['lock_version' => 1, 'name' => 'Nope'])->assertForbidden();
    });
});

describe('functional currency (MD-AC-05)', function () {
    it('can be changed while the company is in setup', function () {
        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => 1, 'functional_currency' => 'USD'])
            ->assertOk()->assertJsonPath('data.functional_currency', 'USD');
    });

    it('cannot be changed once the company is active, but the same value is accepted', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $version = Company::find($this->companyId)->lock_version;

        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => $version, 'functional_currency' => 'USD'])
            ->assertUnprocessable()
            ->assertJson(['code' => 'FUNCTIONAL_CURRENCY_LOCKED'])
            ->assertJsonValidationErrors(['functional_currency']);

        expect(Company::find($this->companyId)->functional_currency)->toBe('IDR');

        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => $version, 'functional_currency' => 'IDR', 'name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
    });
});

describe('activation (MD-FR-01)', function () {
    it('activates a company with no open prerequisites and audits it', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');

        expect(AuditEntry::where('event', 'company.activated')->where('company_id', $this->companyId)->count())->toBe(1);
    });

    it('refuses while a prerequisite is missing and leaves the company in setup', function () {
        FakeActivationRequirement::$met = false;
        $this->app->when(ActivateCompany::class)->needs('$requirements')->give(fn () => [new FakeActivationRequirement]);

        $this->actingAs($this->admin)->postJson("{$this->url}/activate")
            ->assertUnprocessable()
            ->assertJson(['code' => 'COMPANY_NOT_READY', 'errors' => ['chart_of_accounts' => ['The chart of accounts is not confirmed.']]]);

        expect(Company::find($this->companyId)->status->value)->toBe('setup');

        FakeActivationRequirement::$met = true;

        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('cannot be repeated', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();

        $this->actingAs($this->admin)->postJson("{$this->url}/activate")
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);
    });

    it('needs company.activate', function () {
        $this->actingAs(memberWithRoles($this->companyId, 'preparer'))->postJson("{$this->url}/activate")->assertForbidden();
    });
});

describe('deactivate and reactivate (MD-FR-01)', function () {
    it('follows setup to active to inactive and back to active', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")
            ->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);
        $this->actingAs($this->admin)->postJson("{$this->url}/reactivate")->assertStatus(409);

        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->url}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

        expect(AuditEntry::whereIn('event', ['company.deactivated', 'company.reactivated'])->where('company_id', $this->companyId)->count())->toBe(2);
    });

    it('needs the matching permission', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->postJson("{$this->url}/deactivate")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/reactivate")->assertForbidden();
    });
});

describe('read-only while inactive (MD-FR-01)', function () {
    it('accepts writes in setup and active but refuses them when inactive, while reads and reactivation still work', function () {
        $probe = "{$this->url}/_probe/things";

        $this->actingAs($this->admin)->postJson($probe)->assertCreated();

        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson($probe)->assertCreated();

        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        $this->actingAs($this->admin)->postJson($probe)->assertStatus(409)->assertJson(['code' => 'COMPANY_READ_ONLY']);
        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => 99, 'name' => 'x'])
            ->assertStatus(409)->assertJson(['code' => 'COMPANY_READ_ONLY']);
        $this->actingAs($this->admin)->getJson($probe)->assertOk();
        $this->actingAs($this->admin)->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->actingAs($this->admin)->postJson("{$this->url}/reactivate")->assertOk();
        $this->actingAs($this->admin)->postJson($probe)->assertCreated();
    });

    it('does not reveal the status to someone without access to the company', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        $this->actingAs(User::factory()->create())->postJson("{$this->url}/_probe/things")
            ->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    });
});
