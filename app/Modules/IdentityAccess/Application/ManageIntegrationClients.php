<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\IntegrationClient;
use App\Modules\IdentityAccess\Domain\IntegrationClientStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Registers, limits, rotates and revokes the integration clients of the current company
 * (IA-FR-06). Each is a Passport Client Credentials client plus the company, `source_system`,
 * scopes and transaction type allowlist kept here. The secret is generated once, handed back once
 * and stored only as Passport's hash. Rotation and revocation revoke the active tokens in the same
 * transaction, and the guard also checks the client on every request (IA-AC-07).
 *
 * A client can be limited to some dimension values like a member (IA-FR-03). A user who is limited
 * themselves cannot register, change or give new credentials to a client: the client could reach
 * what the user cannot. Revoking stays open to them, as it only takes access away.
 */
final class ManageIntegrationClients
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly ClientRepository $oauthClients,
        private readonly Audit $audit,
        private readonly IntegrationClientDataScopes $dataScopes,
        private readonly MembershipDataScopes $actorLimits,
    ) {}

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $transactionTypeKeys
     * @param  list<string>  $dataScope  dimension value IDs the client is limited to; empty for no limit
     * @param  string|null  $webhookUrl  where the client wants its webhooks (API-TR-25); the secret that signs them is issued by `rotateWebhookSecret`
     * @return array{client: IntegrationClient, secret: string} `secret` is shown to the caller this once
     *
     * @throws ValidationException when the source system is already used in the company
     * @throws ApiException FORBIDDEN for a user who is limited, DIMENSION_INVALID for a value that is not the company's
     */
    public function register(string $name, string $sourceSystem, array $scopes, array $transactionTypeKeys, int $actorId, array $dataScope = [], ?string $webhookUrl = null): array
    {
        $this->actorLimits->assertActorUnrestricted();

        return DB::transaction(function () use ($name, $sourceSystem, $scopes, $transactionTypeKeys, $actorId, $dataScope, $webhookUrl): array {
            $companyId = $this->company->require();

            $taken = IntegrationClient::query()->where('source_system', $sourceSystem)->exists();

            if ($taken) {
                throw ValidationException::withMessages(['source_system' => [__('integration.source_system_taken', ['source_system' => $sourceSystem])]]);
            }

            $oauth = $this->oauthClients->createClientCredentialsGrantClient($name);

            $client = IntegrationClient::query()->create([
                'oauth_client_id' => $oauth->getKey(),
                'name' => $name,
                'source_system' => $sourceSystem,
                'scopes' => $this->unique($scopes),
                'transaction_type_keys' => $this->unique($transactionTypeKeys),
                'webhook_url' => $webhookUrl,
                'created_by' => $actorId,
            ])->refresh();

            $this->dataScopes->replace($client->id, $companyId, $dataScope);

            $this->audit->record(
                'integration_client.created',
                "Integration client {$name} registered for {$sourceSystem}.",
                $client,
                after: $this->snapshot($client),
                companyId: $companyId,
            );

            return ['client' => $client, 'secret' => (string) $oauth->getAttribute('plain_secret')];
        });
    }

    /**
     * @param  array{name?: string, scopes?: list<string>, transaction_type_keys?: list<string>, data_scope?: list<string>, webhook_url?: string|null}  $data
     *
     * @throws ApiException VERSION_CONFLICT, INVALID_STATE_TRANSITION when revoked, FORBIDDEN for a user who is limited
     */
    public function update(string $id, array $data, int $expectedVersion): IntegrationClient
    {
        $this->actorLimits->assertActorUnrestricted();

        return DB::transaction(function () use ($id, $data, $expectedVersion): IntegrationClient {
            $client = $this->lock($id);
            $before = $this->snapshot($client);

            $changes = array_intersect_key($data, array_flip(['name', 'scopes', 'transaction_type_keys', 'webhook_url']));

            foreach (['scopes', 'transaction_type_keys'] as $list) {
                if (isset($changes[$list])) {
                    $changes[$list] = $this->unique($changes[$list]);
                }
            }

            $client->updateLocked($changes, $expectedVersion);

            if (array_key_exists('data_scope', $data)) {
                $this->dataScopes->replace($client->id, $client->company_id, $data['data_scope']);

                // A change of the scope alone leaves the row untouched, yet stale editors must conflict.
                if ($client->lock_version === $expectedVersion && $this->snapshot($client) !== $before) {
                    $client->forceFill(['lock_version' => $expectedVersion + 1])->save();
                }
            }

            if (isset($changes['name'])) {
                $this->oauthClient($client)->forceFill(['name' => $changes['name']])->save();
            }

            $this->audit->record(
                'integration_client.updated',
                "Integration client {$client->name} updated.",
                $client,
                before: $before,
                after: $this->snapshot($client),
                companyId: $client->company_id,
            );

            return $client->refresh();
        });
    }

    /**
     * Issues a new secret and revokes every active token, so the old secret and tokens stop at once.
     *
     * @return array{client: IntegrationClient, secret: string}
     *
     * @throws ApiException INVALID_STATE_TRANSITION when revoked, FORBIDDEN for a user who is limited
     */
    public function rotateSecret(string $id): array
    {
        $this->actorLimits->assertActorUnrestricted();

        return DB::transaction(function () use ($id): array {
            $client = $this->lock($id);
            $oauth = $this->oauthClient($client);

            $oauth->forceFill(['secret' => Str::random(40)])->save();
            $this->revokeTokens($client);

            $client->forceFill(['secret_rotated_at' => now(), 'lock_version' => $client->lock_version + 1])->save();

            $this->audit->record(
                'integration_client.secret_rotated',
                "Secret of integration client {$client->name} rotated.",
                $client,
                companyId: $client->company_id,
            );

            return ['client' => $client->refresh(), 'secret' => (string) $oauth->getAttribute('plain_secret')];
        });
    }

    /**
     * Issues the secret that signs the webhooks of the client, replacing the previous one at once. It is
     * shown to the caller this once; the webhooks go out only when the client has both a URL and a secret.
     *
     * @return array{client: IntegrationClient, secret: string}
     *
     * @throws ApiException INVALID_STATE_TRANSITION when revoked, FORBIDDEN for a user who is limited
     */
    public function rotateWebhookSecret(string $id): array
    {
        $this->actorLimits->assertActorUnrestricted();

        return DB::transaction(function () use ($id): array {
            $client = $this->lock($id);
            $secret = 'whsec_'.Str::random(40);

            $client->forceFill(['webhook_secret' => $secret, 'lock_version' => $client->lock_version + 1])->save();

            $this->audit->record(
                'integration_client.webhook_secret_rotated',
                "Webhook secret of integration client {$client->name} rotated.",
                $client,
                companyId: $client->company_id,
            );

            return ['client' => $client->refresh(), 'secret' => $secret];
        });
    }

    /**
     * Revokes the client for good: no new tokens, and active ones stop. Register a new client to
     * resume the integration.
     *
     * @throws ApiException INVALID_STATE_TRANSITION when already revoked
     */
    public function revoke(string $id): IntegrationClient
    {
        return DB::transaction(function () use ($id): IntegrationClient {
            $client = IntegrationClient::query()->lockForUpdate()->findOrFail($id);

            if ($client->status === IntegrationClientStatus::Revoked) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $this->oauthClient($client)->forceFill(['revoked' => true])->save();
            $this->revokeTokens($client);

            $client->forceFill([
                'status' => IntegrationClientStatus::Revoked,
                'revoked_at' => now(),
                'lock_version' => $client->lock_version + 1,
            ])->save();

            $this->audit->record(
                'integration_client.revoked',
                "Integration client {$client->name} revoked.",
                $client,
                before: ['status' => 'active'],
                after: ['status' => 'revoked'],
                companyId: $client->company_id,
            );

            return $client->refresh();
        });
    }

    /** @throws ApiException INVALID_STATE_TRANSITION when revoked */
    private function lock(string $id): IntegrationClient
    {
        $client = IntegrationClient::query()->lockForUpdate()->findOrFail($id);

        if ($client->status === IntegrationClientStatus::Revoked) {
            throw new ApiException(ErrorCode::InvalidStateTransition);
        }

        return $client;
    }

    private function oauthClient(IntegrationClient $client): Client
    {
        return $this->oauthClients->find($client->oauth_client_id) ?? throw new ApiException(ErrorCode::ServerError);
    }

    private function revokeTokens(IntegrationClient $client): void
    {
        DB::table('oauth_access_tokens')->where('client_id', $client->oauth_client_id)->update(['revoked' => true]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function unique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /** @return array{name: string, source_system: string, scopes: list<string>, transaction_type_keys: list<string>, webhook_url: string|null, data_scope: list<string>, status: string} */
    private function snapshot(IntegrationClient $client): array
    {
        return [
            'name' => $client->name,
            'source_system' => $client->source_system,
            'scopes' => $client->scopes,
            'transaction_type_keys' => $client->transaction_type_keys,
            'webhook_url' => $client->webhook_url,
            'data_scope' => $this->dataScopes->valueIds([$client->id])[$client->id],
            'status' => $client->status->value,
        ];
    }
}
