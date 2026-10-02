<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyConfig;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
    $this->currencies = "{$this->url}/currencies";
});

function activateCurrency(string $code, array $overrides = [])
{
    return test()->actingAs(test()->admin)->postJson(test()->currencies, array_merge(['currency' => $code], $overrides));
}

describe('the ISO 4217 reference (MD-FR-06)', function () {
    it('lists currencies with their minor unit and filters by code or name', function () {
        $all = $this->actingAs($this->admin)->getJson("{$this->url}/iso-currencies?per_page=200")->assertOk();
        $byCode = collect($all->json('data'))->keyBy('code');

        expect($byCode['IDR'])->toBe(['code' => 'IDR', 'name' => 'Rupiah', 'minor_unit' => 2])
            ->and($byCode['JPY']['minor_unit'])->toBe(0)
            ->and($byCode['KWD']['minor_unit'])->toBe(3)
            ->and($all->json('meta.total'))->toBeGreaterThan(150);

        $this->actingAs($this->admin)->getJson("{$this->url}/iso-currencies?filter[search]=jp")->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'JPY');
        $this->actingAs($this->admin)->getJson("{$this->url}/iso-currencies?filter[search]=dollar&per_page=200")->assertJsonPath('data.0.code', 'AUD');
    });
});

describe('activating currencies (MD-FR-06)', function () {
    it('activates the functional currency with the ISO minor unit when the company is created', function () {
        $this->actingAs($this->admin)->getJson($this->currencies)->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [[
            'code' => 'IDR', 'name' => 'Rupiah', 'minor_unit' => 2, 'scale' => 2, 'rounding_mode' => 'half_up',
            'is_functional' => true, 'status' => 'active', 'lock_version' => 1,
        ]]]);

        $other = User::factory()->create();
        $response = $this->actingAs($other)->postJson('/api/v1/workspaces', ['workspace_name' => 'Tokyo', 'company_name' => 'KK Tokyo', 'functional_currency' => 'JPY'])->assertCreated();

        $this->actingAs($other)->getJson('/api/v1/companies/'.$response->json('data.company_id').'/currencies')->assertJsonPath('data.0.code', 'JPY')->assertJsonPath('data.0.scale', 0);
    });

    it('activates another currency with defaults or an explicit scale and rounding', function () {
        activateCurrency('USD')->assertCreated()->assertJson(['data' => [
            'code' => 'USD', 'scale' => 2, 'rounding_mode' => 'half_up', 'is_functional' => false, 'status' => 'active', 'lock_version' => 1,
        ]]);
        activateCurrency('KWD', ['scale' => 4, 'rounding_mode' => 'half_even'])->assertCreated()->assertJsonPath('data.scale', 4)->assertJsonPath('data.rounding_mode', 'half_even');

        $list = $this->actingAs($this->admin)->getJson($this->currencies)->assertOk();

        expect(array_column($list->json('data'), 'code'))->toBe(['IDR', 'KWD', 'USD'])
            ->and(AuditEntry::where('event', 'currency.activated')->where('company_id', $this->companyId)->count())->toBe(2);

        $this->actingAs($this->admin)->getJson("{$this->currencies}/USD")->assertOk()->assertJsonPath('data.name', 'US Dollar');
        $this->actingAs($this->admin)->getJson("{$this->currencies}/EUR")->assertNotFound();
    });

    it('refuses unknown codes, repeats, and a bad scale or rounding mode', function () {
        activateCurrency('XXX')->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        activateCurrency('usd')->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        activateCurrency('IDR')->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        activateCurrency('USD', ['scale' => 7])->assertUnprocessable()->assertJsonValidationErrors(['scale']);
        activateCurrency('USD', ['scale' => -1])->assertUnprocessable()->assertJsonValidationErrors(['scale']);
        activateCurrency('USD', ['rounding_mode' => 'banker'])->assertUnprocessable()->assertJsonValidationErrors(['rounding_mode']);
    });

    it('filters the list by status and search, and keeps companies apart', function () {
        activateCurrency('USD')->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->currencies}/USD/deactivate")->assertOk();

        $this->actingAs($this->admin)->getJson("{$this->currencies}?filter[status]=inactive")->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'USD');
        $this->actingAs($this->admin)->getJson("{$this->currencies}?filter[search]=rupi")->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'IDR');

        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/currencies")->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/currencies/USD")->assertNotFound();
    });
});

describe('changing scale, rounding and status (MD-FR-06)', function () {
    it('changes scale and rounding with lock_version and audits the change', function () {
        activateCurrency('USD')->assertCreated();

        $this->actingAs($this->admin)->patchJson("{$this->currencies}/USD", ['lock_version' => 1, 'scale' => 4, 'rounding_mode' => 'down'])
            ->assertOk()->assertJsonPath('data.scale', 4)->assertJsonPath('data.rounding_mode', 'down')->assertJsonPath('data.lock_version', 2);
        $this->actingAs($this->admin)->patchJson("{$this->currencies}/USD", ['lock_version' => 1, 'scale' => 3])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->actingAs($this->admin)->patchJson("{$this->currencies}/USD", ['lock_version' => 2, 'scale' => 9])->assertUnprocessable()->assertJsonValidationErrors(['scale']);

        $audit = AuditEntry::where('event', 'currency.updated')->firstOrFail();

        expect($audit->attribute_changes->all())->toBe(['old' => ['scale' => 2, 'rounding_mode' => 'half_up'], 'attributes' => ['scale' => 4, 'rounding_mode' => 'down']]);
    });

    it('deactivates and reactivates a currency, but never the functional one', function () {
        activateCurrency('USD')->assertCreated();

        $this->actingAs($this->admin)->postJson("{$this->currencies}/USD/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson("{$this->currencies}/USD/deactivate")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
        $this->actingAs($this->admin)->postJson("{$this->currencies}/USD/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($this->admin)->postJson("{$this->currencies}/IDR/deactivate")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    });

    it('lets the scale of the functional currency change only while the company is in setup', function () {
        $this->actingAs($this->admin)->patchJson("{$this->currencies}/IDR", ['lock_version' => 1, 'scale' => 0])->assertOk()->assertJsonPath('data.scale', 0);
        $this->actingAs($this->admin)->patchJson("{$this->currencies}/IDR", ['lock_version' => 2, 'rounding_mode' => 'half_even'])->assertOk();

        confirmChartOfAccounts($this->admin, $this->companyId);
        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();

        $this->actingAs($this->admin)->patchJson("{$this->currencies}/IDR", ['lock_version' => 3, 'scale' => 2])->assertUnprocessable()->assertJsonValidationErrors(['scale']);
        $this->actingAs($this->admin)->patchJson("{$this->currencies}/IDR", ['lock_version' => 3, 'scale' => 0, 'rounding_mode' => 'half_up'])->assertOk();
    });

    it('refuses changes once the company is inactive', function () {
        confirmChartOfAccounts($this->admin, $this->companyId);
        setUpFiscalCalendar($this->admin, $this->companyId);
        $this->actingAs($this->admin)->postJson("{$this->url}/activate")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/deactivate")->assertOk();

        $this->actingAs($this->admin)->getJson($this->currencies)->assertOk();
        activateCurrency('USD')->assertStatus(409)->assertJsonPath('code', 'COMPANY_READ_ONLY');
    });
});

describe('the functional currency (MD-FR-01, MD-FR-06, MD-AC-05)', function () {
    it('must be an ISO currency, when updating the company and when creating the workspace', function () {
        $company = $this->actingAs($this->admin)->getJson($this->url)->json('data');

        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => $company['lock_version'], 'functional_currency' => 'XXX'])
            ->assertUnprocessable()->assertJsonValidationErrors(['functional_currency']);
        $this->actingAs($this->admin)->postJson('/api/v1/workspaces', ['workspace_name' => 'X', 'company_name' => 'X', 'functional_currency' => 'XXX'])
            ->assertUnprocessable()->assertJsonValidationErrors(['functional_currency']);
    });

    it('is activated when it changes during setup, and the previous one stays configurable', function () {
        $company = $this->actingAs($this->admin)->getJson($this->url)->json('data');

        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => $company['lock_version'], 'functional_currency' => 'USD'])->assertOk();

        $list = collect($this->actingAs($this->admin)->getJson($this->currencies)->json('data'))->keyBy('code');

        expect($list['USD']['is_functional'])->toBeTrue()->and($list['USD']['status'])->toBe('active')
            ->and($list['IDR']['is_functional'])->toBeFalse();

        $this->actingAs($this->admin)->postJson("{$this->currencies}/IDR/deactivate")->assertOk();
    });

    it('reactivates a currency that becomes functional again', function () {
        activateCurrency('USD')->assertCreated();
        $this->actingAs($this->admin)->postJson("{$this->currencies}/USD/deactivate")->assertOk();

        $company = $this->actingAs($this->admin)->getJson($this->url)->json('data');
        $this->actingAs($this->admin)->patchJson($this->url, ['lock_version' => $company['lock_version'], 'functional_currency' => 'USD'])->assertOk();

        $this->actingAs($this->admin)->getJson("{$this->currencies}/USD")->assertJsonPath('data.status', 'active');
    });
});

describe('the Currencies contract and rounding', function () {
    it('reports configuration, activity and the functional scale per company', function () {
        $currencies = app(Currencies::class);
        activateCurrency('USD', ['scale' => 3, 'rounding_mode' => 'down'])->assertCreated();

        expect($currencies->isKnown('USD'))->toBeTrue()->and($currencies->isKnown('XXX'))->toBeFalse()
            ->and($currencies->find($this->companyId, 'USD'))->toEqual(new CurrencyConfig('USD', 3, CurrencyRounding::Down, true))
            ->and($currencies->find($this->companyId, 'EUR'))->toBeNull()
            ->and($currencies->isActive($this->companyId, 'USD'))->toBeTrue()
            ->and($currencies->isActive($this->companyId, 'EUR'))->toBeFalse()
            ->and($currencies->functionalScale($this->companyId))->toBe(2);

        $this->actingAs($this->admin)->postJson("{$this->currencies}/USD/deactivate");

        expect($currencies->isActive($this->companyId, 'USD'))->toBeFalse()->and($currencies->find($this->companyId, 'USD')->active)->toBeFalse();
    });

    it('rounds to the scale with each rounding mode', function () {
        $round = fn (CurrencyRounding $mode, string $amount, int $scale = 2): string => (new CurrencyConfig('USD', $scale, $mode, true))->round($amount);

        expect($round(CurrencyRounding::HalfUp, '1.005'))->toBe('1.01')
            ->and($round(CurrencyRounding::HalfUp, '-1.005'))->toBe('-1.01')
            ->and($round(CurrencyRounding::HalfEven, '1.005'))->toBe('1.00')
            ->and($round(CurrencyRounding::HalfEven, '1.015'))->toBe('1.02')
            ->and($round(CurrencyRounding::Up, '1.001'))->toBe('1.01')
            ->and($round(CurrencyRounding::Down, '1.999'))->toBe('1.99')
            ->and($round(CurrencyRounding::HalfUp, BigDecimal::of('1234.5'), 0))->toBe('1235')
            ->and($round(CurrencyRounding::HalfUp, '1', 3))->toBe('1.000');
    });
});

describe('the ledger follows the scale of the functional currency', function () {
    beforeEach(function () {
        $this->yen = User::factory()->create();
        $response = $this->actingAs($this->yen)->postJson('/api/v1/workspaces', ['workspace_name' => 'Tokyo', 'company_name' => 'KK Tokyo', 'functional_currency' => 'JPY'])->assertCreated();
        $this->yenCompany = $response->json('data.company_id');
        $this->yenUrl = "/api/v1/companies/{$this->yenCompany}";

        confirmChartOfAccounts($this->yen, $this->yenCompany);
        setUpFiscalCalendar($this->yen, $this->yenCompany);
        $this->bank = (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->yenCompany)->where('code', '1112')->value('id');
        $this->sales = (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->yenCompany)->where('code', '4110')->value('id');
    });

    function yenJournal(string $amount): PostJournal
    {
        return new PostJournal(
            companyId: test()->yenCompany,
            accountingDate: '2026-03-15',
            description: 'Sale',
            source: JournalSource::Entry,
            lines: [new PostJournalLine(test()->bank, $amount, '0'), new PostJournalLine(test()->sales, '0', $amount)],
        );
    }

    it('refuses amounts with more decimals than the scale and accepts whole yen', function () {
        expect(fn () => app(PostingService::class)->post(yenJournal('1000.50')))->toThrow(ValidationException::class);

        $journal = app(PostingService::class)->post(yenJournal('1000'));

        $this->actingAs($this->yen)->getJson("{$this->yenUrl}/journals/{$journal->id}")->assertOk()
            ->assertJsonPath('data.lines.0.debit', '1000')->assertJsonPath('data.lines.1.credit', '1000');
    });

    it('shows the trial balance at that scale and lets the company use another scale before activation', function () {
        app(PostingService::class)->post(yenJournal('1000'));

        $report = $this->actingAs($this->yen)->getJson("{$this->yenUrl}/reports/trial-balance?from=2026-01-01&to=2026-12-31")->assertOk();

        expect(collect($report->json('data.rows'))->firstWhere('code', '1112')['debit'])->toBe('1000')
            ->and($report->json('data.totals.debit'))->toBe('1000')
            ->and($report->json('data.balanced'))->toBeTrue();

        $this->actingAs($this->yen)->patchJson("{$this->yenUrl}/currencies/JPY", ['lock_version' => 1, 'scale' => 2])->assertOk();

        expect(fn () => app(PostingService::class)->post(yenJournal('1000.50')))->not->toThrow(ValidationException::class);
    });
});

describe('access', function () {
    it('lets a viewer read currencies but not change them', function () {
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson($this->currencies)->assertOk();
        $this->actingAs($viewer)->getJson("{$this->url}/iso-currencies")->assertOk();
        $this->actingAs($viewer)->postJson($this->currencies, ['currency' => 'USD'])->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$this->currencies}/IDR", ['lock_version' => 1, 'scale' => 0])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->currencies}/IDR/deactivate")->assertForbidden();
    });
});
