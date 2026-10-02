<?php

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\CurrencyRestriction;
use App\Modules\GeneralLedger\Contracts\DimensionRequirement;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Audit\AuditEntry;
use App\Support\Company\CompanyScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->bank = attributeAccountId($this->companyId, '1112');
    $this->sales = attributeAccountId($this->companyId, '4110');
});

function attributeAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

/** Changes the posting attributes of an account through the API; the account is at lock_version 1 unless given. */
function patchAttributes(string $accountId, array $attributes, int $version = 1): TestResponse
{
    return test()->actingAs(test()->admin)->patchJson(test()->url."/accounts/{$accountId}", ['lock_version' => $version] + $attributes);
}

function attributeJournal(string $debitAccount, string $creditAccount, JournalSource $source = JournalSource::Entry, array $lines = []): PostJournal
{
    return new PostJournal(
        companyId: test()->companyId,
        accountingDate: '2026-03-15',
        description: 'Attribute test',
        source: $source,
        lines: $lines ?: [new PostJournalLine($debitAccount, '1000', '0'), new PostJournalLine($creditAccount, '0', '1000')],
    );
}

function attributeFailure(PostJournal $command): ApiException
{
    try {
        app(PostingService::class)->post($command);
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the posting to fail.');
}

function departmentTypeId(): string
{
    return (string) collect(test()->actingAs(test()->admin)->getJson(test()->url.'/dimension-types')->json('data'))->firstWhere('key', 'department')['id'];
}

function departmentValueId(string $code = 'SALES'): string
{
    return (string) test()->actingAs(test()->admin)->postJson(test()->url.'/dimension-values', [
        'dimension_type_id' => departmentTypeId(), 'code' => $code, 'name' => ucfirst(strtolower($code)),
    ])->assertCreated()->json('data.id');
}

describe('storing posting attributes (GL-FR-02)', function () {
    it('starts every account without restrictions', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/accounts", ['code' => '9000', 'name' => 'Plain', 'class' => 'asset'])
            ->assertCreated()
            ->assertJsonPath('data.allowed_sources', ['entry', 'subledger', 'system'])
            ->assertJsonPath('data.dimension_requirements', [])
            ->assertJsonPath('data.currency_restriction', 'any')
            ->assertJsonPath('data.restricted_currency', null)
            ->assertJsonPath('data.is_revaluable', false);

        $account = app(AccountDirectory::class)->find($this->companyId, $this->bank);

        expect($account->allowedSources)->toBe([JournalSource::Entry, JournalSource::Subledger, JournalSource::System])
            ->and($account->dimensionRequirements)->toBe([])
            ->and($account->currencyRestriction)->toBe(CurrencyRestriction::Any)
            ->and($account->isRevaluable)->toBeFalse();
    });

    it('creates an account with its attributes and reads them back through the contract', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

        $id = $this->actingAs($this->admin)->postJson("{$this->url}/accounts", [
            'code' => '9115', 'name' => 'Bank USD', 'class' => 'asset',
            'allowed_sources' => ['subledger'],
            'dimension_requirements' => ['department' => 'required', 'project' => 'optional'],
            'currency_restriction' => 'specific',
            'restricted_currency' => 'usd',
            'is_revaluable' => true,
        ])->assertCreated()
            ->assertJsonPath('data.allowed_sources', ['subledger'])
            ->assertJsonPath('data.dimension_requirements', ['department' => 'required'])
            ->assertJsonPath('data.currency_restriction', 'specific')
            ->assertJsonPath('data.restricted_currency', 'USD')
            ->assertJsonPath('data.is_revaluable', true)
            ->json('data.id');

        $account = app(AccountDirectory::class)->find($this->companyId, $id);

        expect($account->allowedSources)->toBe([JournalSource::Subledger])
            ->and($account->dimensionRequirements)->toBe(['department' => DimensionRequirement::Required])
            ->and($account->currencyRestriction)->toBe(CurrencyRestriction::Specific)
            ->and($account->restrictedCurrency)->toBe('USD');
    });

    it('updates attributes with optimistic locking and audits only what changed', function () {
        patchAttributes($this->bank, ['allowed_sources' => ['entry', 'system'], 'is_revaluable' => true])
            ->assertOk()
            ->assertJsonPath('data.allowed_sources', ['entry', 'system'])
            ->assertJsonPath('data.is_revaluable', true)
            ->assertJsonPath('data.lock_version', 2);

        patchAttributes($this->bank, ['is_revaluable' => false])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');

        $audit = AuditEntry::where('event', 'account.updated')->where('company_id', $this->companyId)->sole();

        expect($audit->attribute_changes['old'])->toEqualCanonicalizing(['allowed_sources' => ['entry', 'subledger', 'system'], 'is_revaluable' => false])
            ->and($audit->attribute_changes['attributes'])->toEqualCanonicalizing(['allowed_sources' => ['entry', 'system'], 'is_revaluable' => true]);
    });

    it('drops optional entries and removes a restriction by setting it to optional', function () {
        $first = patchAttributes($this->bank, ['dimension_requirements' => ['department' => 'required', 'project' => 'forbidden']])->assertOk();

        expect($first->json('data.dimension_requirements'))->toEqualCanonicalizing(['department' => 'required', 'project' => 'forbidden']);

        patchAttributes($this->bank, ['dimension_requirements' => ['department' => 'optional', 'project' => 'forbidden']], 2)
            ->assertOk()->assertJsonPath('data.dimension_requirements', ['project' => 'forbidden']);
    });

    it('clears the restricted currency when the restriction leaves `specific`', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();

        patchAttributes($this->bank, ['currency_restriction' => 'specific', 'restricted_currency' => 'USD'])->assertOk();
        patchAttributes($this->bank, ['currency_restriction' => 'any'], 2)
            ->assertOk()->assertJsonPath('data.currency_restriction', 'any')->assertJsonPath('data.restricted_currency', null);
    });

    it('refuses attributes that do not make sense, naming the field', function () {
        patchAttributes($this->bank, ['allowed_sources' => []])->assertUnprocessable()->assertJsonValidationErrors(['allowed_sources']);
        patchAttributes($this->bank, ['allowed_sources' => ['entry', 'entry']])->assertUnprocessable()->assertJsonValidationErrors(['allowed_sources.1']);
        patchAttributes($this->bank, ['allowed_sources' => ['manual']])->assertUnprocessable()->assertJsonValidationErrors(['allowed_sources.0']);
        patchAttributes($this->bank, ['dimension_requirements' => ['department' => 'mandatory']])->assertUnprocessable()->assertJsonValidationErrors(['dimension_requirements.department']);
        patchAttributes($this->bank, ['dimension_requirements' => ['nope' => 'required']])->assertUnprocessable()->assertJsonValidationErrors(['dimension_requirements.nope']);
        patchAttributes($this->bank, ['currency_restriction' => 'specific'])->assertUnprocessable()->assertJsonValidationErrors(['restricted_currency']);
        patchAttributes($this->bank, ['currency_restriction' => 'specific', 'restricted_currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['restricted_currency']);
        patchAttributes($this->bank, ['currency_restriction' => 'specific', 'restricted_currency' => 'IDR'])->assertUnprocessable()->assertJsonValidationErrors(['restricted_currency']);
        patchAttributes($this->bank, ['currency_restriction' => 'specific', 'restricted_currency' => 'XXX'])->assertUnprocessable()->assertJsonValidationErrors(['restricted_currency']);
        patchAttributes($this->bank, ['currency_restriction' => 'any', 'restricted_currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['restricted_currency']);
        patchAttributes($this->bank, ['currency_restriction' => 'functional_only', 'is_revaluable' => true])->assertUnprocessable()->assertJsonValidationErrors(['is_revaluable']);

        expect(Account::withoutGlobalScope(CompanyScope::class)->find($this->bank)->lock_version)->toBe(1);
    });

    it('refuses a dimension type that is inactive, but keeps an old rule on one that was deactivated later', function () {
        patchAttributes($this->bank, ['dimension_requirements' => ['project' => 'required']])->assertOk();
        $project = collect($this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->json('data'))->firstWhere('key', 'project');
        $this->actingAs($this->admin)->postJson("{$this->url}/dimension-types/{$project['id']}/deactivate")->assertOk();

        patchAttributes($this->sales, ['dimension_requirements' => ['project' => 'required']])->assertUnprocessable()->assertJsonValidationErrors(['dimension_requirements.project']);
        patchAttributes($this->bank, ['is_revaluable' => true], 2)->assertOk()->assertJsonPath('data.dimension_requirements', ['project' => 'required']);
    });

    it('keeps the database consistent even when the application is bypassed', function () {
        expect(fn () => DB::table('gl_accounts')->where('id', $this->bank)->update(['currency_restriction' => 'specific']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('gl_accounts')->where('id', $this->bank)->update(['currency_restriction' => 'functional_only', 'is_revaluable' => true]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('gl_accounts')->where('id', $this->bank)->update(['allowed_sources' => '[]']))->toThrow(QueryException::class);
    });
});

describe('posting against account attributes (GL-AC-02)', function () {
    it('refuses a source the account does not accept, naming the line', function () {
        patchAttributes($this->bank, ['allowed_sources' => ['subledger']])->assertOk();

        $failure = attributeFailure(attributeJournal($this->bank, $this->sales));

        expect($failure->errorCode->value)->toBe('POSTING_SOURCE_NOT_ALLOWED')
            ->and(array_keys($failure->errors))->toBe(['lines.0.account_id'])
            ->and($failure->errors['lines.0.account_id'][0])->toContain('1112')->toContain('entry')
            ->and(DB::table('gl_journals')->count())->toBe(0);

        expect(attributeFailure(attributeJournal($this->bank, $this->sales, JournalSource::System))->errorCode->value)->toBe('POSTING_SOURCE_NOT_ALLOWED');

        $posted = app(PostingService::class)->post(attributeJournal($this->bank, $this->sales, JournalSource::Subledger));

        expect($posted->replayed)->toBeFalse()->and(DB::table('gl_journals')->count())->toBe(1);
    });

    it('requires a dimension value where the account demands one', function () {
        patchAttributes($this->sales, ['dimension_requirements' => ['department' => 'required']])->assertOk();

        $failure = attributeFailure(attributeJournal($this->bank, $this->sales));

        expect($failure->errorCode->value)->toBe('DIMENSION_REQUIRED')
            ->and(array_keys($failure->errors))->toBe(['lines.1.dimensions.department'])
            ->and($failure->errors['lines.1.dimensions.department'][0])->toContain('4110')->toContain('department');

        $sales = departmentValueId();

        app(PostingService::class)->post(attributeJournal($this->bank, $this->sales, lines: [
            new PostJournalLine($this->bank, '1000', '0'),
            new PostJournalLine($this->sales, '0', '1000', dimensions: ['department' => $sales]),
        ]));

        expect(DB::table('gl_journals')->count())->toBe(1);
    });

    it('refuses a dimension value on an account that forbids the type', function () {
        patchAttributes($this->bank, ['dimension_requirements' => ['department' => 'forbidden']])->assertOk();
        $value = departmentValueId();

        $failure = attributeFailure(attributeJournal($this->bank, $this->sales, lines: [
            new PostJournalLine($this->bank, '1000', '0', dimensions: ['department' => $value]),
            new PostJournalLine($this->sales, '0', '1000'),
        ]));

        expect($failure->errorCode->value)->toBe('DIMENSION_INVALID')
            ->and(array_keys($failure->errors))->toBe(['lines.0.dimensions.department']);

        // Without the forbidden type the same account posts, and optional types stay free.
        app(PostingService::class)->post(attributeJournal($this->bank, $this->sales, lines: [
            new PostJournalLine($this->bank, '1000', '0'),
            new PostJournalLine($this->sales, '0', '1000', dimensions: ['department' => $value]),
        ]));

        expect(DB::table('gl_journals')->count())->toBe(1);
    });

    it('reports missing dimensions before forbidden ones when a journal has both problems', function () {
        patchAttributes($this->bank, ['dimension_requirements' => ['department' => 'forbidden']])->assertOk();
        patchAttributes($this->sales, ['dimension_requirements' => ['department' => 'required']])->assertOk();
        $value = departmentValueId();

        $failure = attributeFailure(attributeJournal($this->bank, $this->sales, lines: [
            new PostJournalLine($this->bank, '1000', '0', dimensions: ['department' => $value]),
            new PostJournalLine($this->sales, '0', '1000'),
        ]));

        expect($failure->errorCode->value)->toBe('DIMENSION_REQUIRED')
            ->and(array_keys($failure->errors))->toBe(['lines.1.dimensions.department', 'lines.0.dimensions.department']);
    });

    it('refuses a line whose currency the account does not accept', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/currencies", ['currency' => 'USD'])->assertCreated();
        patchAttributes($this->bank, ['currency_restriction' => 'specific', 'restricted_currency' => 'USD'])->assertOk();

        $failure = attributeFailure(attributeJournal($this->bank, $this->sales));

        expect($failure->errorCode->value)->toBe('ACCOUNT_CURRENCY_NOT_ALLOWED')
            ->and(array_keys($failure->errors))->toBe(['lines.0.currency'])
            ->and($failure->errors['lines.0.currency'][0])->toContain('1112')->toContain('IDR');

        // An account limited to the functional currency, and one without restriction, take it.
        patchAttributes($this->bank, ['currency_restriction' => 'functional_only'], 2)->assertOk();
        app(PostingService::class)->post(attributeJournal($this->bank, $this->sales));

        expect(DB::table('gl_journals')->count())->toBe(1);
    });

    it('keeps the attribute errors in the language of the request', function () {
        patchAttributes($this->bank, ['allowed_sources' => ['subledger']])->assertOk();

        app()->setLocale('id');

        expect(attributeFailure(attributeJournal($this->bank, $this->sales))->errors['lines.0.account_id'][0])->toContain('tidak menerima posting');
    });
});
