<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\TransactionEntry\Contracts\FinancialEvents;
use App\Orchestration\Webhooks\DeliverFinancialEventWebhook;
use App\Orchestration\Webhooks\SignedWebhookJob;
use App\Platform\Audit\AuditEntry;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
use App\Support\Company\CompanyScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

describe('idempotency of the source key (TE-FR-13, TE-AC-04, API-AC-04)', function () {
    it('answers an identical resend with the original result and no second entry', function () {
        $first = evSendEvent()->assertCreated()->json('data');

        $again = evSendEvent()->assertOk()->json('data');

        expect($again)->toBe($first)->and(evEntries())->toBe(1);
    });

    it('does not care for the order of keys or the format of decimals', function () {
        $first = evSendEvent()->assertCreated()->json('data');

        $payload = evPayload();
        $payload['amounts'] = ['withholding_payable' => '500000.00', 'net_payable' => '9500000.0', 'salary_expense' => '010000000'];
        $payload['references'] = array_reverse($payload['references']);
        $payload['dimensions'] = [];

        evSend('POST', "{$this->url}/financial-events", $payload, $this->token)->assertOk()->assertJsonPath('data.event_id', $first['event_id']);

        expect(evEntries())->toBe(1);
    });

    it('answers a resend with the original result even when the period has closed since', function () {
        $first = evSendEvent()->assertCreated()->json('data');

        DB::table('gl_periods')->where('company_id', $this->companyId)->update(['status' => 'closed']);

        evSendEvent()->assertOk()->assertJsonPath('data.event_id', $first['event_id']);
    });

    it('returns where the original stands now, with its journal once posted', function () {
        $first = evSendEvent()->assertCreated()->json('data');

        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$first['event_id']}/approve")->assertOk();
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$first['event_id']}/post")->assertOk();

        evSendEvent()->assertOk()->assertJsonPath('data.event_id', $first['event_id'])->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.journal_number', fn ($number) => $number !== null);

        expect(DB::table('gl_journals')->count())->toBe(1);
    });

    it('refuses other content under the same key and leaves the original as it was (409)', function () {
        $first = evSendEvent()->assertCreated()->json('data');

        $changed = ['amounts' => ['salary_expense' => '10000001', 'net_payable' => '9500001', 'withholding_payable' => '500000']];
        evSendEvent($changed)->assertConflict()->assertJsonPath('code', 'SOURCE_TRANSACTION_CONFLICT');

        evSendEvent(['accounting_date' => '2026-03-30'])->assertConflict()->assertJsonPath('code', 'SOURCE_TRANSACTION_CONFLICT');
        evSendEvent(['references' => ['source_transaction_id' => 'payrun-2026-03', 'source_document_number' => 'PAYRUN-2026-03-B']])->assertConflict();

        $entry = $this->actingAs($this->admin)->getJson("{$this->url}/entries/{$first['event_id']}")->assertOk()->json('data');

        expect(evEntries())->toBe(1)->and($entry['values']['salary_expense'])->toBe('10000000')->and($entry['lock_version'])->toBeInt();
    });

    it('keeps the key apart per source system and per company', function () {
        $first = evSendEvent()->assertCreated()->json('data');

        $other = evClient(['name' => 'Other', 'source_system' => 'other']);
        $second = evSendEvent([], evToken($other))->assertCreated()->json('data');

        expect($second['event_id'])->not->toBe($first['event_id'])->and(evEntries())->toBe(2);
    });

    it('makes a new key a new event', function () {
        evSendEvent()->assertCreated();
        evSendEvent(['references' => ['source_transaction_id' => 'payrun-2026-04']])->assertCreated();

        expect(evEntries())->toBe(2);
    });

    it('records nothing for an invalid payload, so that the corrected one under the same key is new', function () {
        evSendEvent(['amounts' => ['salary_expense' => '100', 'net_payable' => '90', 'withholding_payable' => '0']])->assertUnprocessable();

        expect(evEntries())->toBe(0);

        evSendEvent()->assertCreated();
    });

    it('holds one entry per key in the database, whatever the application does', function () {
        evSendEvent()->assertCreated();

        $row = (array) DB::table('te_entries')->where('company_id', $this->companyId)->first();
        unset($row['id']);
        $row['id'] = (string) Str::ulid();
        $row['number'] = 'DUP-1';

        expect(fn () => DB::table('te_entries')->insert($row))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('changes feed (TE-FR-15, TE-AC-08, API-TR-16)', function () {
    function evChanges(array $query = [], ?string $token = null)
    {
        return evSend('GET', test()->url.'/financial-events/changes'.($query === [] ? '' : '?'.http_build_query($query)), [], $token ?? test()->token);
    }

    it('gives every step once and in order, and polling from the last cursor misses nothing', function () {
        $first = evSendEvent()->assertCreated()->json('data.event_id');
        $page = evChanges()->assertOk()->json();

        expect($page['data'])->toHaveCount(1)
            ->and($page['data'][0])->toMatchArray(['event_id' => $first, 'action' => 'submit', 'status' => 'submitted', 'journal_id' => null])
            ->and($page['meta']['next_cursor'])->toBe($page['data'][0]['cursor']);

        // Nothing new: the same cursor comes back and the page is empty.
        $empty = evChanges(['cursor' => $page['meta']['next_cursor']])->assertOk()->json();
        expect($empty['data'])->toBe([])->and($empty['meta']['next_cursor'])->toBe($page['meta']['next_cursor']);

        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$first}/approve")->assertOk();
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$first}/post")->assertOk();
        $second = evSendEvent(['references' => ['source_transaction_id' => 'payrun-2026-04']])->assertCreated()->json('data.event_id');

        $next = evChanges(['cursor' => $page['meta']['next_cursor']])->assertOk()->json();

        expect(array_column($next['data'], 'action'))->toBe(['approve', 'post', 'submit'])
            ->and(array_column($next['data'], 'status'))->toBe(['approved', 'posted', 'submitted'])
            ->and($next['data'][1]['journal_number'])->not->toBeNull()->and($next['data'][0]['journal_id'])->toBeNull()
            ->and($next['data'][2]['event_id'])->toBe($second);

        $cursors = array_map('intval', array_column($next['data'], 'cursor'));
        expect($cursors)->toBe([...$cursors])->and(collect($cursors)->sort()->values()->all())->toBe($cursors)->and($cursors[0])->toBeGreaterThan((int) $page['meta']['next_cursor']);
    });

    it('pages with a limit without skipping or repeating', function () {
        foreach (['a', 'b', 'c'] as $key) {
            evSendEvent(['references' => ['source_transaction_id' => $key]])->assertCreated();
        }

        $seen = [];
        $cursor = null;

        do {
            $page = evChanges(array_filter(['limit' => 2, 'cursor' => $cursor]))->assertOk()->json();
            array_push($seen, ...array_column($page['data'], 'cursor'));
            $cursor = $page['meta']['next_cursor'];
        } while ($page['data'] !== []);

        expect($seen)->toHaveCount(3)->and(array_unique($seen))->toHaveCount(3);
    });

    it('shows a client only the steps of its own events, and a user with financial-events.read all', function () {
        evSendEvent()->assertCreated();

        $other = evClient(['name' => 'Other', 'source_system' => 'other']);
        $theirs = evSendEvent([], evToken($other))->assertCreated()->json('data.event_id');

        expect(evChanges([], evToken($other))->assertOk()->json('data.*.event_id'))->toBe([$theirs])
            ->and(evChanges()->assertOk()->json('data'))->toHaveCount(1);

        $this->actingAs($this->viewer)->getJson("{$this->url}/financial-events/changes")->assertOk()->assertJsonCount(2, 'data');
    });

    it('leaves out entries that did not come from an event', function () {
        $this->actingAs($this->admin)->getJson("{$this->url}/financial-events/changes")->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses a bad cursor or limit, and a client without the read scope', function () {
        evChanges(['cursor' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
        evChanges(['limit' => 0])->assertUnprocessable()->assertJsonValidationErrors(['limit']);

        $writer = evClient(['name' => 'Writer', 'source_system' => 'writer', 'scopes' => ['financial-events:write']]);
        evChanges([], evToken($writer))->assertForbidden();
        evSend('GET', "{$this->url}/financial-events/changes")->assertUnauthorized();
    });

    it('numbers the steps of a company without gaps', function () {
        evSendEvent()->assertCreated();
        evSendEvent(['references' => ['source_transaction_id' => 'x']])->assertCreated();

        expect(DB::table('te_entry_decisions')->where('company_id', $this->companyId)->orderBy('seq')->pluck('seq')->map(fn ($n) => (int) $n)->all())->toBe([1, 2]);
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

describe('batch of events (TE-FR-17, API-TR-26)', function () {
    function evBatch(array $events, ?string $token = null)
    {
        return evSend('POST', test()->url.'/financial-events/batch', ['events' => $events], $token ?? test()->token);
    }

    function evItem(string $key, array $overrides = []): array
    {
        return evPayload(array_merge(['references' => ['source_transaction_id' => $key]], $overrides));
    }

    it('answers each event on its own, in order, as the single endpoint would', function () {
        evSendEvent(evItem('known'))->assertCreated();

        $response = evBatch([
            evItem('new-1'),
            evItem('known'),
            evItem('known', ['amounts' => ['salary_expense' => '1', 'net_payable' => '1', 'withholding_payable' => '0']]),
            evItem('bad', ['amounts' => ['salary_expense' => '100', 'net_payable' => '90', 'withholding_payable' => '0']]),
            evItem('new-2'),
        ])->assertOk();

        expect($response->json('data.*.index'))->toBe([0, 1, 2, 3, 4])
            ->and($response->json('data.*.status'))->toBe([201, 200, 409, 422, 201])
            ->and($response->json('data.0.data.status'))->toBe('submitted')
            ->and($response->json('data.1.data.event_id'))->not->toBeNull()->and($response->json('data.1'))->not->toHaveKey('error')
            ->and($response->json('data.2.error.code'))->toBe('SOURCE_TRANSACTION_CONFLICT')
            ->and($response->json('data.3.error'))->toHaveKeys(['message', 'errors'])->and($response->json('data.3'))->not->toHaveKey('data')
            ->and(evEntries())->toBe(3);
    });

    it('records nothing for the bad event, so that the corrected one under the same key is new', function () {
        evBatch([evItem('a', ['amounts' => ['salary_expense' => '100', 'net_payable' => '90', 'withholding_payable' => '0']])])->assertOk()->assertJsonPath('data.0.status', 422);

        expect(evEntries())->toBe(0);

        evBatch([evItem('a')])->assertOk()->assertJsonPath('data.0.status', 201);
    });

    it('is safe to send again as a whole', function () {
        $batch = [evItem('a'), evItem('b')];

        $first = evBatch($batch)->assertOk()->json('data');
        $again = evBatch($batch)->assertOk()->json('data');

        expect(array_column($again, 'status'))->toBe([200, 200])
            ->and(array_column(array_column($again, 'data'), 'event_id'))->toBe(array_column(array_column($first, 'data'), 'event_id'))
            ->and(evEntries())->toBe(2);
    });

    it('treats the same key twice in one batch as a resend', function () {
        $response = evBatch([evItem('a'), evItem('a'), evItem('a', ['accounting_date' => '2026-03-30'])])->assertOk();

        expect($response->json('data.*.status'))->toBe([201, 200, 409])->and(evEntries())->toBe(1);
    });

    it('refuses an event outside the allowlist for that event only, without saying anything about the type', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'general_journal', 'label' => 'Jurnal Umum'])->assertCreated();

        $response = evBatch([evItem('a', ['transaction_type' => ['key' => 'general_journal']]), evItem('b')])->assertOk();

        expect($response->json('data.*.status'))->toBe([403, 201])->and($response->json('data.0.error'))->not->toHaveKey('errors')
            ->and($response->json('data.0.error.code'))->toBe('FORBIDDEN')->and(evEntries())->toBe(1);
    });

    it('refuses what the single endpoint refuses, such as source_system and unknown sections', function () {
        $response = evBatch([evItem('a', ['source_system' => 'hr']), evItem('b', ['employees' => []]), evPayload(['references' => ['source_document_number' => 'x']])])->assertOk();

        expect($response->json('data.*.status'))->toBe([422, 422, 422])
            ->and(array_keys($response->json('data.0.error.errors')))->toContain('source_system')
            ->and(array_keys($response->json('data.1.error.errors')))->toContain('employees')
            ->and(array_keys($response->json('data.2.error.errors')))->toContain('references.source_transaction_id')
            ->and(evEntries())->toBe(0);
    });

    it('takes the source system from the client for every event', function () {
        $first = evBatch([evItem('a')])->assertOk()->json('data.0.data.event_id');

        expect($this->actingAs($this->admin)->getJson("{$this->url}/entries/{$first}")->json('data.source_system'))->toBe('payroll');
    });

    it('refuses a bad envelope as a whole: none, too many, not a list, items that are no events', function () {
        evBatch([])->assertUnprocessable()->assertJsonValidationErrors(['events']);
        evBatch(array_map(fn (int $n) => evItem("e{$n}"), range(1, config('api.batch.max_events') + 1)))->assertUnprocessable()->assertJsonValidationErrors(['events']);
        evBatch(['a' => evItem('a')])->assertUnprocessable()->assertJsonValidationErrors(['events']);
        evBatch(['text'])->assertUnprocessable()->assertJsonValidationErrors(['events.0']);
        evSend('POST', "{$this->url}/financial-events/batch", [], $this->token)->assertUnprocessable()->assertJsonValidationErrors(['events']);

        expect(evEntries())->toBe(0);
    });

    it('takes a batch up to the limit', function () {
        config(['api.batch.max_events' => 3]);

        evBatch([evItem('a'), evItem('b'), evItem('c')])->assertOk()->assertJsonCount(3, 'data');
        evBatch([evItem('a'), evItem('b'), evItem('c'), evItem('d')])->assertUnprocessable();
    });

    it('keeps the events on the approval path like any other: submitted, never decided by the client', function () {
        $id = evBatch([evItem('a')])->assertOk()->json('data.0.data.event_id');

        evSend('POST', "{$this->url}/entries/{$id}/approve", [], $this->token)->assertForbidden();
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$id}/approve")->assertOk();
    });

    it('asks for credentials and the scope to write, and is for clients, not users', function () {
        evSend('POST', "{$this->url}/financial-events/batch", ['events' => [evItem('a')]])->assertUnauthorized();

        $reader = evClient(['name' => 'Reader', 'source_system' => 'reader', 'scopes' => ['financial-events:read:self']]);
        evBatch([evItem('a')], evToken($reader))->assertForbidden();

        $this->actingAs($this->admin)->postJson("{$this->url}/financial-events/batch", ['events' => [evItem('a')]])->assertForbidden();

        expect(evEntries())->toBe(0);
    });

    it('does not reach another company', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');

        evSend('POST', "/api/v1/companies/{$otherCompany}/financial-events/batch", ['events' => [evItem('a')]], $this->token)->assertForbidden();
    });
});

describe('webhooks (API-TR-25)', function () {
    /** Points the client at the URL and issues the secret that signs what is sent; returns the secret. */
    function evWebhook(array $client, string $url = 'https://hooks.example.com/payroll'): string
    {
        $base = test()->url."/integration-clients/{$client['id']}";
        $version = test()->actingAs(test()->admin)->getJson($base)->assertOk()->json('data.lock_version');

        test()->actingAs(test()->admin)->patchJson($base, ['lock_version' => $version, 'webhook_url' => $url])->assertOk();

        return test()->actingAs(test()->admin)->postJson("{$base}/rotate-webhook-secret")->assertOk()->json('data.webhook_secret');
    }

    /** @return list<Request> the webhooks sent to the host, oldest first */
    function evSentWebhooks(string $host = 'hooks.example.com'): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === $host)
            ->values()->all();
    }

    beforeEach(function () {
        // What the receivers answer; a test sets it to make them fail.
        $this->receiverStatus = 200;
        Http::fake(fn () => Http::response('', $this->receiverStatus));
        $this->secret = evWebhook($this->payroll);
    });

    it('sends a signed webhook for the step an event takes, with the same content as the changes feed', function () {
        $event = evSendEvent()->assertCreated()->json('data.event_id');

        $sent = evSentWebhooks();
        expect($sent)->toHaveCount(1);

        $request = $sent[0];
        $body = $request->body();
        $webhook = json_decode($body, true);
        $id = $request->header('Webhook-Id')[0];
        $timestamp = $request->header('Webhook-Timestamp')[0];

        expect($request->method())->toBe('POST')
            ->and($webhook)->toMatchArray(['id' => $id, 'type' => 'financial_event.changed'])
            ->and($webhook['data'])->toMatchArray(['event_id' => $event, 'action' => 'submit', 'status' => 'submitted', 'journal_id' => null, 'journal_number' => null])
            ->and($request->header('Webhook-Signature')[0])->toBe('v1='.hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $this->secret))
            ->and(abs((int) $timestamp - now()->timestamp))->toBeLessThan(60);

        $feed = evChanges()->assertOk()->json('data.0');
        expect($webhook['data'])->toBe($feed);
    });

    it('names each step with an ID of its own, and follows the event to its posting by whoever takes the step', function () {
        $event = evSendEvent()->assertCreated()->json('data.event_id');
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$event}/approve")->assertOk();
        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$event}/post")->assertOk();

        $webhooks = array_map(fn ($request) => json_decode($request->body(), true), evSentWebhooks());

        expect(array_column(array_column($webhooks, 'data'), 'action'))->toBe(['submit', 'approve', 'post'])
            ->and(array_column(array_column($webhooks, 'data'), 'status'))->toBe(['submitted', 'approved', 'posted'])
            ->and(array_unique(array_column($webhooks, 'id')))->toHaveCount(3)
            ->and($webhooks[2]['data']['journal_number'])->not->toBeNull();
    });

    it('sends nothing to a client without a URL or without a secret', function () {
        $noSecret = evClient(['name' => 'Plain', 'source_system' => 'plain', 'webhook_url' => 'https://hooks.example.com/plain']);
        evSendEvent([], evToken($noSecret))->assertCreated();

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$this->payroll['id']}", ['lock_version' => 3, 'webhook_url' => null])->assertOk();
        evSendEvent()->assertCreated();

        expect(evSentWebhooks())->toBe([]);
    });

    it('sends the webhooks of a client to that client only', function () {
        $other = evClient(['name' => 'Other', 'source_system' => 'other']);
        evWebhook($other, 'https://other.example.com/hook');

        evSendEvent([], evToken($other))->assertCreated();

        expect(evSentWebhooks())->toBe([])->and(evSentWebhooks('other.example.com'))->toHaveCount(1);
    });

    it('names the company in every webhook, inside what is signed', function () {
        evSendEvent()->assertCreated();

        expect(json_decode(evSentWebhooks()[0]->body(), true)['company_id'])->toBe($this->companyId);
    });

    it('never reaches the client of another company, whatever a row of the outbox says', function () {
        $other = User::factory()->create();
        [$otherCompany] = createWorkspaceAs($other, 'Other');
        $theirs = $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/integration-clients", [
            'name' => 'Theirs', 'source_system' => 'theirs', 'scopes' => ['financial-events:write'], 'webhook_url' => 'https://other.example.com/hook',
        ])->assertCreated()->json('data');
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/integration-clients/{$theirs['id']}/rotate-webhook-secret")->assertOk();

        $deliver = app(DeliverFinancialEventWebhook::class);
        $message = fn (string $company, string $client) => new OutboxMessage((string) Str::ulid(), $company, 'financial_event.changed', $client, ['seq' => 1], 0);

        // Their client named under our company, and ours under theirs: each is unknown where it is looked up.
        $deliver($message($this->companyId, $theirs['id']));
        $deliver($message($otherCompany, $this->payroll['id']));

        expect(Http::recorded())->toHaveCount(0);
    });

    it('sends nothing to a client that was revoked', function () {
        $event = evSendEvent()->assertCreated()->json('data.event_id');
        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$this->payroll['id']}/revoke")->assertOk();

        $this->actingAs($this->approver)->postJson("{$this->url}/entries/{$event}/approve")->assertOk();

        expect(evSentWebhooks())->toHaveCount(1);
    });

    it('sends nothing for an entry that no client sent', function () {
        $this->actingAs($this->admin)->postJson("{$this->url}/entries", [
            'transaction_type_key' => 'payroll_summary', 'accounting_date' => '2026-03-31',
            'values' => ['salary_expense' => '1', 'net_payable' => '1', 'withholding_payable' => '0'],
        ]);

        expect(evSentWebhooks())->toBe([]);
    });

    it('keeps the event when the receiver is down, and records the webhook that never got through', function () {
        config(['webhook-server.tries' => 1]);
        $this->receiverStatus = 503;

        $event = evSendEvent()->assertCreated()->json('data.event_id');

        $failure = AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.webhook_failed')->first();

        expect($failure)->not->toBeNull()->and($failure->outcome)->toBe('failed')
            ->and($failure->properties['client_id'])->toBe($this->payroll['id'])->and($failure->properties['status'])->toBe(503)
            ->and($failure->properties['url'])->toBe('https://hooks.example.com/payroll')
            ->and(evChanges()->assertOk()->json('data.0.event_id'))->toBe($event);
    });

    it('tries again later when the relay fails, with the same webhook ID', function () {
        $failing = true;
        app(Outbox::class)->handle(
            FinancialEvents::CHANGED_TOPIC,
            function (OutboxMessage $message) use (&$failing) {
                if ($failing) {
                    throw new RuntimeException('queue down');
                }

                app(DeliverFinancialEventWebhook::class)($message);
            },
        );

        evSendEvent()->assertCreated();
        expect(evSentWebhooks())->toBe([]);

        $failing = false;
        $this->travel(11)->seconds();
        app(Outbox::class)->relay();
        $this->travelBack();

        $id = DB::table('pf_outbox')->where('company_id', $this->companyId)->value('id');
        expect(evSentWebhooks())->toHaveCount(1)->and(evSentWebhooks()[0]->header('Webhook-Id')[0])->toBe($id);
    });

    it('signs again on each attempt, so that a late retry carries a fresh timestamp the signature covers', function () {
        $job = new SignedWebhookJob;
        $job->uuid = 'whk-1';
        $job->secret = 'whsec_test';
        $job->payload = ['a' => 1];

        (function () {
            $this->addTimestampToHeaders();
        })->call($job);
        $first = $job->headers;

        $this->travel(2)->hours();
        (function () {
            $this->addTimestampToHeaders();
        })->call($job);
        $second = $job->headers;
        $this->travelBack();

        expect($second['Webhook-Timestamp'])->not->toBe($first['Webhook-Timestamp'])
            ->and($second['Webhook-Signature'])->not->toBe($first['Webhook-Signature'])
            ->and($second['Webhook-Signature'])->toBe('v1='.hash_hmac('sha256', "whk-1.{$second['Webhook-Timestamp']}.".json_encode(['a' => 1]), 'whsec_test'));
    });
});
