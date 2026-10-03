<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Support\Company\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Financial events from integration clients (TE-FR-12, API-TR-16 to API-TR-20, API-AC-03, API-AC-05, API-AC-06).

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";

    confirmChartOfAccounts($this->admin, $this->companyId);
    setUpFiscalCalendar($this->admin, $this->companyId);

    $this->approver = memberWithRoles($this->companyId, 'approver');
    $this->viewer = memberWithRoles($this->companyId, 'viewer');

    $this->expense = evAccountId('6210');
    $this->bank = evAccountId('1112');
    $this->tax = evAccountId('4130');

    $this->typeId = $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'payroll_summary', 'label' => 'Ringkasan Payroll'])->assertCreated()->json('data.id');
    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions", [
        'mode' => 'structured',
        'fields' => [
            ['name' => 'salary_expense', 'type' => 'amount', 'label' => 'Beban gaji'],
            ['name' => 'net_payable', 'type' => 'amount', 'label' => 'Gaji bersih'],
            ['name' => 'withholding_payable', 'type' => 'amount', 'label' => 'Potongan'],
            ['name' => 'dept', 'type' => 'dimension', 'label' => 'Departemen', 'dimension_type' => 'department', 'required' => false],
        ],
        'posting_rules' => [
            ['type' => 'amount', 'side' => 'debit', 'amount_field' => 'salary_expense', 'account' => ['source' => 'fixed', 'account_id' => $this->expense], 'apply_dimensions' => true],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'net_payable', 'account' => ['source' => 'fixed', 'account_id' => $this->bank]],
            ['type' => 'amount', 'side' => 'credit', 'amount_field' => 'withholding_payable', 'account' => ['source' => 'fixed', 'account_id' => $this->tax]],
        ],
    ])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types/{$this->typeId}/versions/2/publish")->assertOk();

    $this->payroll = evClient();
    $this->token = evToken($this->payroll);
});

function evAccountId(string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', test()->companyId)->where('code', $code)->value('id');
}

function evClient(array $overrides = []): array
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/integration-clients', array_merge([
        'name' => 'Payroll', 'source_system' => 'payroll',
        'scopes' => ['financial-events:write', 'financial-events:read:self'],
        'transaction_type_keys' => ['payroll_summary'],
    ], $overrides))->assertCreated()->json('data');
}

function evToken(array $client, ?string $scope = null): string
{
    app('auth')->forgetGuards();

    return test()->post('/api/v1/oauth/token', ['grant_type' => 'client_credentials', 'scope' => $scope ?? implode(' ', $client['scopes'])],
        ['Authorization' => 'Basic '.base64_encode($client['client_id'].':'.$client['client_secret']), 'Accept' => 'application/json'])
        ->assertOk()->json('access_token');
}

/** Requests as the machine: only the Bearer token and no leftover session user. */
function evSend(string $method, string $uri, array $data = [], ?string $token = null)
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return test()->json($method, $uri, $data, $token === null ? [] : ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]);
}

function evPayload(array $overrides = []): array
{
    return array_merge([
        'transaction_type' => ['key' => 'payroll_summary', 'version' => 2],
        'accounting_date' => '2026-03-31',
        'currency' => 'IDR',
        'amounts' => ['salary_expense' => '10000000', 'net_payable' => '9500000', 'withholding_payable' => '500000'],
        'dimensions' => ['department' => null],
        'references' => ['source_transaction_id' => 'payrun-2026-03', 'source_document_number' => 'PAYRUN-2026-03', 'employee_count' => 42],
    ], $overrides);
}

function evSendEvent(array $overrides = [], ?string $token = null)
{
    return evSend('POST', test()->url.'/financial-events', evPayload($overrides), $token ?? test()->token);
}

function evEntries(): int
{
    return DB::table('te_entries')->where('company_id', test()->companyId)->count();
}

describe('receiving an event (TE-FR-12, API-TR-19, API-TR-20)', function () {
    it('records an entry from the source integration and returns its identity and status', function () {
        $event = evSendEvent()->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.journal_id', null)->assertJsonPath('data.journal_number', null)->json('data');

        $entry = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$event['event_id']}")->assertOk()->json('data');

        expect($entry)->toMatchArray([
            'source' => 'integration', 'source_system' => 'payroll', 'source_transaction_id' => 'payrun-2026-03', 'created_by' => null,
            'transaction_type_key' => 'payroll_summary', 'transaction_type_version' => 2, 'status' => 'submitted',
        ])->and($entry['values'])->toMatchArray(['salary_expense' => '10000000'])
            ->and($entry['lines'])->toHaveCount(3)
            ->and($entry['decisions'][0])->toMatchArray(['action' => 'submit', 'actor_id' => null, 'client_id' => $this->payroll['id']]);

        expect((array) DB::table('te_entries')->where('id', $event['event_id'])->value('source_references'))->not->toBeNull();
    });

    it('takes the latest published version when none is named', function () {
        evSendEvent(['transaction_type' => ['key' => 'payroll_summary']])->assertCreated();
    });

    it('follows the approval policy: an approver approves and posts, never the client (API-AC-05)', function () {
        $event = evSendEvent()->assertCreated()->json('data');

        evSend('POST', "{$this->url}/entries/{$event['event_id']}/approve", [], $this->token)->assertForbidden();

        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$event['event_id']}/approve")->assertOk();
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$event['event_id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        evSend('GET', "{$this->url}/financial-events/{$event['event_id']}", [], $this->token)->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.journal_number', fn ($number) => $number !== null);
    });

    it('is approved and posted at once when the policy says so for the source', function () {
        $this->actingAs($this->admin)->putJson("{$this->url}/approval-policy", [
            'lock_version' => 0, 'separation_of_duties' => true,
            'rules' => [['transaction_type_key' => null, 'source' => 'integration', 'threshold' => null, 'stages' => [], 'auto_post' => true]],
        ])->assertOk();

        $event = evSendEvent()->assertCreated()->assertJsonPath('data.status', 'posted')->json('data');

        expect($event['journal_id'])->not->toBeNull();

        $lines = DB::table('gl_journal_lines')->where('journal_id', $event['journal_id'])->get();

        expect((float) $lines->sum('debit'))->toBe(10000000.0)->and((float) $lines->sum('credit'))->toBe(10000000.0);
    });

    it('takes the source system from the client, never from the body (API-AC-05)', function () {
        evSendEvent(['source_system' => 'hr'])->assertUnprocessable()->assertJsonValidationErrors(['source_system']);

        expect(evEntries())->toBe(0);
    });

    it('applies the dimensions of the event to the lines that ask for them', function () {
        $type = collect($this->actingAs($this->admin)->getJson("{$this->url}/dimension-types")->json('data'))->firstWhere('key', 'department')['id'];
        $sales = $this->actingAs($this->admin)->postJson("{$this->url}/dimension-values", ['dimension_type_id' => $type, 'code' => 'SALES', 'name' => 'Sales'])->assertCreated()->json('data.id');

        $event = evSendEvent(['dimensions' => ['department' => $sales]])->assertCreated()->json('data');

        $lines = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$event['event_id']}")->json('data.lines');

        expect($lines[0]['dimensions'])->toBe(['department' => $sales])->and($lines[1]['dimensions'])->toBe([]);
    });
});

describe('invalid events leave nothing behind (422)', function () {
    it('refuses what the payload or the configuration does not hold', function () {
        $cases = [
            'no source transaction id' => [['references' => ['source_document_number' => 'x']], 'references.source_transaction_id'],
            'an amount that is not a string' => [['amounts' => ['salary_expense' => 10000000, 'net_payable' => '9500000', 'withholding_payable' => '500000']], 'amounts.salary_expense'],
            'an amount with a decimal comma' => [['amounts' => ['salary_expense' => '10000000,50', 'net_payable' => '9500000', 'withholding_payable' => '500000']], 'amounts.salary_expense'],
            'an amount the version has no field for' => [['amounts' => ['salary_expense' => '1', 'net_payable' => '1', 'withholding_payable' => '0', 'bonus' => '5']], 'amounts.bonus'],
            'a missing amount' => [['amounts' => ['salary_expense' => '10000000', 'net_payable' => '9500000']], 'amounts.withholding_payable'],
            'more decimals than the currency has' => [['amounts' => ['salary_expense' => '1.005', 'net_payable' => '1.005', 'withholding_payable' => '0']], 'amounts.salary_expense'],
            'a dimension the version does not have' => [['dimensions' => ['project' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']], 'dimensions.project'],
            'a section that belongs to no canonical payload' => [['employees' => [['name' => 'Ani']]], 'employees'],
            'a date no period accepts' => [['accounting_date' => '2030-03-31'], 'accounting_date'],
            'a currency the company has not activated' => [['currency' => 'JPY'], 'currency'],
            'a rate the version does not let the event bring' => [['currency' => 'IDR', 'exchange_rate' => '1'], 'exchange_rate'],
            'a transaction type that does not exist' => [['transaction_type' => ['key' => 'payroll_summary', 'version' => 9]], 'transaction_type_version'],
            'lines that do not balance' => [['amounts' => ['salary_expense' => '100', 'net_payable' => '90', 'withholding_payable' => '0']], 'lines'],
        ];

        foreach ($cases as $name => [$overrides, $field]) {
            $response = evSendEvent($overrides);

            expect($response->status())->toBe(422, $name);
            $response->assertJsonStructure(['message', 'errors']);

            expect(in_array($field, array_keys($response->json('errors')), true))->toBeTrue($name);
        }

        expect(evEntries())->toBe(0)->and(DB::table('gl_journals')->count())->toBe(0);
    });

    it('does not take an event for a type with only a free-lines version', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();
        $client = evClient(['name' => 'Other', 'source_system' => 'other', 'transaction_type_keys' => ['general_journal']]);

        evSendEvent(['transaction_type' => ['key' => 'general_journal']], evToken($client))->assertUnprocessable()->assertJsonValidationErrors(['transaction_type.key']);
    });

    it('does not take an unknown type that the client is allowed to name', function () {
        $client = evClient(['name' => 'Other', 'source_system' => 'other', 'transaction_type_keys' => ['nope']]);

        evSendEvent(['transaction_type' => ['key' => 'nope']], evToken($client))->assertUnprocessable()->assertJsonValidationErrors(['transaction_type.key']);
    });
});

describe('who may send and read (401, 403)', function () {
    it('asks for credentials', function () {
        evSend('POST', "{$this->url}/financial-events", evPayload())->assertUnauthorized();
        evSend('POST', "{$this->url}/financial-events", evPayload(), 'not-a-token')->assertUnauthorized();

        expect(evEntries())->toBe(0);
    });

    it('refuses a transaction type outside the allowlist without saying anything about it', function () {
        $client = evClient(['name' => 'Other', 'source_system' => 'other', 'transaction_type_keys' => ['something_else']]);

        $response = evSendEvent([], evToken($client))->assertForbidden();

        expect($response->json())->not->toHaveKey('errors')->and(evEntries())->toBe(0);
    });

    it('refuses a client without the scope to write, or to read its own', function () {
        $reader = evClient(['name' => 'Reader', 'source_system' => 'reader', 'scopes' => ['financial-events:read:self']]);
        evSendEvent([], evToken($reader))->assertForbidden();

        $writer = evClient(['name' => 'Writer', 'source_system' => 'writer', 'scopes' => ['financial-events:write']]);
        $event = evSendEvent([], evToken($writer))->assertCreated()->json('data');
        evSend('GET', "{$this->url}/financial-events/{$event['event_id']}", [], evToken($writer))->assertForbidden();

        // A token asks for fewer scopes than its client holds, and gets only those.
        $token = evToken($this->payroll, 'financial-events:read:self');
        evSendEvent([], $token)->assertForbidden();
    });

    it('does not reach another company', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        evSend('POST', "/api/v1/companies/{$otherCompany}/financial-events", evPayload(), $this->token)->assertForbidden();
    });

    it('lets a client read only its own events and answers 404 for another client\'s', function () {
        $mine = evSendEvent()->assertCreated()->json('data.event_id');

        $second = evClient(['name' => 'Second', 'source_system' => 'second']);
        evSend('GET', "{$this->url}/financial-events/{$mine}", [], evToken($second))->assertNotFound();
        evSend('GET', "{$this->url}/financial-events/{$mine}", [], $this->token)->assertOk()->assertJsonPath('data.event_id', $mine);
        evSend('GET', "{$this->url}/financial-events/01JZZZZZZZZZZZZZZZZZZZZZZZ", [], $this->token)->assertNotFound();
    });

    it('lets a user read an event with financial-events.read, but not send one', function () {
        $event = evSendEvent()->assertCreated()->json('data.event_id');

        $this->actingAs($this->viewer)->getJson("{$this->url}/financial-events/{$event}")->assertOk()->assertJsonPath('data.event_id', $event);
        $this->actingAs($this->viewer)->postJson("{$this->url}/financial-events", evPayload())->assertForbidden();
    });
});
