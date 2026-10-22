<?php

use App\Models\User;
use App\Platform\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->create();
    [$this->companyId] = createWorkspaceAs($this->admin);
    $this->url = "/api/v1/companies/{$this->companyId}";
});

/** Registers a client as the admin; returns the response data, which carries the secret once. */
function registerClient(array $overrides = []): array
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/integration-clients', array_merge([
        'name' => 'Payroll',
        'source_system' => 'payroll',
        'scopes' => ['transaction-types:read'],
        'transaction_type_keys' => ['payroll_summary'],
    ], $overrides))->assertCreated()->json('data');
}

/** Asks for a token the way a machine does: form-encoded body, `client_secret_basic`. */
function requestToken(array $client, ?string $scope = null, ?string $secret = null)
{
    app('auth')->forgetGuards();

    return test()->post('/api/v1/oauth/token', array_filter([
        'grant_type' => 'client_credentials',
        'scope' => $scope ?? implode(' ', $client['scopes']),
    ]), ['Authorization' => 'Basic '.base64_encode($client['client_id'].':'.($secret ?? $client['client_secret'])), 'Accept' => 'application/json']);
}

function tokenOf(array $client, ?string $scope = null): string
{
    return requestToken($client, $scope)->assertOk()->json('access_token');
}

/**
 * Requests as the machine: only the Bearer token, with the Idempotency-Key that machines must send,
 * and no leftover session user. The token is sent per request, so later user requests stay users.
 */
function asMachine(string $token, array $server = []): object
{
    return new class($token, $server)
    {
        public function __construct(private string $token, private array $server) {}

        public function getJson(string $uri)
        {
            return $this->send('GET', $uri, []);
        }

        public function postJson(string $uri, array $data = [])
        {
            return $this->send('POST', $uri, $data);
        }

        private function send(string $method, string $uri, array $data)
        {
            // The guard that last authenticated stays the default within one app instance; a real request starts clean.
            app('auth')->forgetGuards();
            app('auth')->shouldUse('web');

            $response = test()->withServerVariables($this->server)->json($method, $uri, $data, [
                'Authorization' => "Bearer {$this->token}",
                'Idempotency-Key' => (string) Str::uuid(),
            ]);
            test()->withServerVariables([]);

            return $response;
        }
    };
}

describe('registering clients (IA-FR-06)', function () {
    it('returns the secret once and keeps only a hash', function () {
        $client = registerClient();

        expect($client['client_secret'])->toBeString()->not->toBeEmpty()
            ->and($client)->toMatchArray(['source_system' => 'payroll', 'status' => 'active', 'scopes' => ['transaction-types:read'], 'transaction_type_keys' => ['payroll_summary']]);

        $stored = DB::table('oauth_clients')->where('id', $client['client_id'])->first();
        expect($stored->secret)->not->toBe($client['client_secret'])
            ->and(json_decode($stored->grant_types, true))->toBe(['client_credentials']);

        $shown = $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients/{$client['id']}")->assertOk();
        expect($shown->json('data'))->not->toHaveKey('client_secret');
        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients")->assertOk()->assertJsonCount(1, 'data');
        expect(json_encode($this->actingAs($this->admin)->getJson("{$this->url}/integration-clients")->json()))->not->toContain($client['client_secret']);

        expect(AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.created')->count())->toBe(1);
    });

    it('keeps source_system unique per company and validates scopes and keys', function () {
        registerClient();
        $url = "{$this->url}/integration-clients";

        $this->actingAs($this->admin)->postJson($url, ['name' => 'Again', 'source_system' => 'payroll', 'scopes' => ['transaction-types:read']])
            ->assertUnprocessable()->assertJsonValidationErrors('source_system');
        $this->actingAs($this->admin)->postJson($url, ['name' => 'X', 'source_system' => 'Bad Name', 'scopes' => ['admin:all'], 'transaction_type_keys' => ['Bad Key']])
            ->assertUnprocessable()->assertJsonValidationErrors(['source_system', 'scopes.0', 'transaction_type_keys.0']);
        $this->actingAs($this->admin)->postJson($url, ['name' => 'X', 'source_system' => 'hr', 'scopes' => []])->assertUnprocessable()->assertJsonValidationErrors('scopes');

        [$otherCompany] = createWorkspaceAs($other = User::factory()->create(), 'Other');
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/integration-clients", ['name' => 'P', 'source_system' => 'payroll', 'scopes' => ['transaction-types:read']])->assertCreated();
    });

    it('is managed only by users with integration-clients.manage, never by a client', function () {
        $client = registerClient(['scopes' => ['transaction-types:read', 'exchange-rates:write']]);
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->getJson("{$this->url}/integration-clients")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$this->url}/integration-clients", ['name' => 'X', 'source_system' => 'x1', 'scopes' => ['transaction-types:read']])->assertForbidden();

        asMachine(tokenOf($client))->getJson("{$this->url}/integration-clients")->assertForbidden();
    });

    it('does not reach clients of another company', function () {
        $client = registerClient();
        [$otherCompany] = createWorkspaceAs($other = User::factory()->create(), 'Other');

        $this->actingAs($other)->getJson("/api/v1/companies/{$otherCompany}/integration-clients/{$client['id']}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/companies/{$otherCompany}/integration-clients/{$client['id']}/revoke")->assertNotFound();
    });
});

describe('token request (API-TR-22, API-TR-23)', function () {
    it('issues a short-lived Bearer token without a refresh token for client_secret_basic', function () {
        $client = registerClient();

        $response = requestToken($client)->assertOk();

        expect($response->json('token_type'))->toBe('Bearer')
            ->and($response->json('expires_in'))->toBe(600)
            ->and($response->json('refresh_token'))->toBeNull()
            ->and($response->json('access_token'))->toBeString();
    });

    it('refuses a wrong secret and an unknown client', function () {
        $client = registerClient();

        requestToken($client, null, 'wrong-secret')->assertUnauthorized();
        requestToken([...$client, 'client_id' => '00000000-0000-4000-8000-000000000000'])->assertUnauthorized();
    });

    it('does not accept other grant types', function () {
        $client = registerClient();
        app('auth')->forgetGuards();

        $this->post('/api/v1/oauth/token', ['grant_type' => 'password', 'username' => 'a@b.test', 'password' => 'x'],
            ['Authorization' => 'Basic '.base64_encode($client['client_id'].':'.$client['client_secret']), 'Accept' => 'application/json'])
            ->assertStatus(400);
    });
});

describe('what a client may do (IA-AC-06)', function () {
    it('reaches only what its scopes grant, in its own company', function () {
        $client = registerClient();
        $token = tokenOf($client);
        $this->actingAs($this->admin)->postJson("{$this->url}/transaction-types", ['key' => 'payroll_summary', 'label' => 'Payroll'])->assertCreated();

        asMachine($token)->getJson("{$this->url}/transaction-types")->assertOk()->assertJsonPath('data.0.key', 'payroll_summary');

        // Not granted by its scope.
        asMachine($token)->getJson("{$this->url}/entries")->assertForbidden();
        asMachine($token)->postJson("{$this->url}/transaction-types", ['key' => 'other', 'label' => 'X'])->assertForbidden();
        asMachine($token)->postJson("{$this->url}/entries/01HZZZZZZZZZZZZZZZZZZZZZZZ/approve")->assertForbidden();
        asMachine($token)->getJson("{$this->url}/memberships")->assertForbidden();
        asMachine($token)->postJson("{$this->url}/exchange-rates", [])->assertForbidden();

        // Another company, and routes written for users only.
        [$otherCompany] = createWorkspaceAs(User::factory()->create(), 'Other');
        asMachine($token)->getJson("/api/v1/companies/{$otherCompany}/transaction-types")->assertForbidden();
        asMachine($token)->getJson('/api/v1/me')->assertUnauthorized();
        asMachine($token)->getJson("{$this->url}/security-policy")->assertForbidden();
    });

    it('maps exchange-rates:write to the rate permission and records the source as api', function () {
        $client = registerClient(['source_system' => 'treasury', 'scopes' => ['exchange-rates:write']]);
        $token = tokenOf($client);

        // Past the permission check the body is validated: 422, not 403.
        asMachine($token)->postJson("{$this->url}/exchange-rates", [])->assertUnprocessable();
        asMachine($token)->getJson("{$this->url}/transaction-types")->assertForbidden();
    });

    it('refuses a token that has no valid signature or is not a client credentials token', function () {
        asMachine('not-a-token')->getJson("{$this->url}/transaction-types")->assertUnauthorized();
        asMachine('')->getJson("{$this->url}/transaction-types")->assertUnauthorized();
    });

    it('does not ask a client for 2FA, which only users can have', function () {
        $client = registerClient();
        $this->actingAs($this->admin)->putJson("{$this->url}/security-policy", [
            'lock_version' => 0, 'two_factor_permissions' => ['transaction-types.read'], 'idle_timeout_minutes' => null, 'client_ip_allowlist' => [],
        ])->assertOk();

        asMachine(tokenOf($client))->getJson("{$this->url}/transaction-types")->assertOk();
    });
});

describe('IP allowlist (IA-FR-05)', function () {
    it('lets a client call only from an allowed address once the policy lists some', function () {
        $client = registerClient();
        $token = tokenOf($client);

        asMachine($token)->getJson("{$this->url}/transaction-types")->assertOk();

        $this->actingAs($this->admin)->putJson("{$this->url}/security-policy", [
            'lock_version' => 0, 'two_factor_permissions' => [], 'idle_timeout_minutes' => null, 'client_ip_allowlist' => ['203.0.113.0/24', '198.51.100.7'],
        ])->assertOk();

        asMachine($token, ['REMOTE_ADDR' => '192.0.2.1'])->getJson("{$this->url}/transaction-types")->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
        asMachine($token, ['REMOTE_ADDR' => '203.0.113.50'])->getJson("{$this->url}/transaction-types")->assertOk();
        asMachine($token, ['REMOTE_ADDR' => '198.51.100.7'])->getJson("{$this->url}/transaction-types")->assertOk();

        // Users are not bound by the client allowlist.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson("{$this->url}/transaction-types")->assertOk();
    });
});

describe('changing, rotating and revoking (IA-AC-07)', function () {
    it('narrows scopes at once, also for tokens already issued, and audits the change', function () {
        $client = registerClient(['scopes' => ['transaction-types:read', 'exchange-rates:write']]);
        $token = tokenOf($client);

        asMachine($token)->postJson("{$this->url}/exchange-rates", [])->assertUnprocessable();

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", [
            'lock_version' => 1, 'scopes' => ['transaction-types:read'], 'transaction_type_keys' => ['payroll_summary', 'hr_summary'],
        ])->assertOk()->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.transaction_type_keys', ['hr_summary', 'payroll_summary']);

        asMachine($token)->postJson("{$this->url}/exchange-rates", [])->assertForbidden();
        asMachine($token)->getJson("{$this->url}/transaction-types")->assertOk();

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'name' => 'Stale'])
            ->assertConflict()->assertJson(['code' => 'VERSION_CONFLICT']);
        expect(AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.updated')->count())->toBe(1);
    });

    it('stops old tokens and the old secret on rotation, and shows the new secret once', function () {
        $client = registerClient();
        $token = tokenOf($client);
        asMachine($token)->getJson("{$this->url}/transaction-types")->assertOk();

        $rotated = $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-secret")->assertOk()->json('data');

        expect($rotated['client_secret'])->not->toBe($client['client_secret']);
        asMachine($token)->getJson("{$this->url}/transaction-types")->assertUnauthorized();
        requestToken($client)->assertUnauthorized();

        $fresh = tokenOf([...$client, 'client_secret' => $rotated['client_secret']]);
        asMachine($fresh)->getJson("{$this->url}/transaction-types")->assertOk();

        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients/{$client['id']}")
            ->assertOk()->assertJsonMissingPath('data.client_secret')->assertJsonPath('data.status', 'active');
        expect(AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.secret_rotated')->count())->toBe(1);
    });

    it('stops everything on revocation, for good', function () {
        $client = registerClient();
        $token = tokenOf($client);

        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/revoke")
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        asMachine($token)->getJson("{$this->url}/transaction-types")->assertUnauthorized();
        requestToken($client)->assertUnauthorized();

        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/revoke")->assertStatus(409)->assertJson(['code' => 'INVALID_STATE_TRANSITION']);
        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-secret")->assertStatus(409);
        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 2, 'name' => 'X'])->assertStatus(409);

        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients?filter[status]=revoked")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients?filter[status]=active")->assertJsonCount(0, 'data');
        expect(AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.revoked')->count())->toBe(1);

        // The source system stays taken: a revoked client is history, not a free name.
        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients", ['name' => 'Again', 'source_system' => 'payroll', 'scopes' => ['transaction-types:read']])
            ->assertUnprocessable();
    });
});

describe('webhook endpoint (API-TR-25)', function () {
    it('takes a URL on registration and on change, and reads it back without any secret', function () {
        $client = registerClient(['webhook_url' => 'https://hooks.example.com/payroll']);

        expect($client)->toMatchArray(['webhook_url' => 'https://hooks.example.com/payroll', 'webhook_enabled' => false]);

        $changed = $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'webhook_url' => 'https://hooks.example.com/v2'])
            ->assertOk()->assertJsonPath('data.webhook_url', 'https://hooks.example.com/v2')->json('data');

        expect($changed)->not->toHaveKey('webhook_secret');
        $audit = AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.updated')->first()->attribute_changes;

        expect($audit['old']['webhook_url'])->toBe('https://hooks.example.com/payroll')->and($audit['attributes']['webhook_url'])->toBe('https://hooks.example.com/v2');
    });

    it('refuses a URL that is not an https URL of a public host', function () {
        foreach (['ftp://hooks.example.com/x', 'hooks.example.com/x', 'https://localhost/x', 'https://app.localhost/x', 'https://10.0.0.5/x', 'https://127.0.0.1/x', 'https://192.168.1.9/x', 'https://[::1]/x', 'https://intranet/x', 'https://user:secret@hooks.example.com/x', 'https:///x', 'javascript:alert(1)'] as $url) {
            $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients", [
                'name' => 'X', 'source_system' => 'x', 'scopes' => ['transaction-types:read'], 'webhook_url' => $url,
            ])->assertUnprocessable()->assertJsonValidationErrors(['webhook_url']);
        }

        $client = registerClient();
        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 1, 'webhook_url' => 'https://10.0.0.5/x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['webhook_url']);
    });

    it('issues the secret that signs the webhooks once, and replaces it on the next issue', function () {
        $client = registerClient(['webhook_url' => 'https://hooks.example.com/payroll']);

        $first = $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-webhook-secret")->assertOk()
            ->assertJsonPath('data.webhook_enabled', true)->json('data');

        expect($first['webhook_secret'])->toStartWith('whsec_')->and(strlen($first['webhook_secret']))->toBeGreaterThan(30);

        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients/{$client['id']}")->assertOk()->assertJsonMissingPath('data.webhook_secret')->assertJsonPath('data.webhook_enabled', true);
        $this->actingAs($this->admin)->getJson("{$this->url}/integration-clients")->assertOk()->assertJsonMissingPath('data.0.webhook_secret');

        $second = $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-webhook-secret")->assertOk()->json('data');

        expect($second['webhook_secret'])->not->toBe($first['webhook_secret'])->and($second['lock_version'])->toBe($first['lock_version'] + 1);
        expect(AuditEntry::where('company_id', $this->companyId)->where('event', 'integration_client.webhook_secret_rotated')->count())->toBe(2)
            ->and(json_encode(AuditEntry::where('company_id', $this->companyId)->get()->toArray()))->not->toContain($second['webhook_secret']);
    });

    it('keeps the secret encrypted in the database', function () {
        $client = registerClient(['webhook_url' => 'https://hooks.example.com/payroll']);
        $secret = $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-webhook-secret")->assertOk()->json('data.webhook_secret');

        $stored = (string) DB::table('ia_integration_clients')->where('id', $client['id'])->value('webhook_secret');

        expect($stored)->not->toBe('')->and($stored)->not->toContain($secret);
    });

    it('is not enabled by a URL alone, and stops with a null URL', function () {
        $client = registerClient(['webhook_url' => 'https://hooks.example.com/payroll']);
        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-webhook-secret")->assertOk();

        $this->actingAs($this->admin)->patchJson("{$this->url}/integration-clients/{$client['id']}", ['lock_version' => 2, 'webhook_url' => null])
            ->assertOk()->assertJsonPath('data.webhook_url', null)->assertJsonPath('data.webhook_enabled', false);
    });

    it('refuses to issue a secret for a revoked client, and needs the permission', function () {
        $client = registerClient();
        $viewer = memberWithRoles($this->companyId, 'viewer');

        $this->actingAs($viewer)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-webhook-secret")->assertForbidden();

        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/revoke")->assertOk();
        $this->actingAs($this->admin)->postJson("{$this->url}/integration-clients/{$client['id']}/rotate-webhook-secret")->assertStatus(409);
    });
});
