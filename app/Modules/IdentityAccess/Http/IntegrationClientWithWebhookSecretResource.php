<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\IntegrationClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An integration client together with the secret that signs its webhooks, for the response that issues it:
 * the only time that secret can be read (API-TR-25).
 *
 * @property-read IntegrationClient $resource
 */
class IntegrationClientWithWebhookSecretResource extends JsonResource
{
    public function __construct(IntegrationClient $client, private readonly string $secret)
    {
        parent::__construct($client);
    }

    /**
     * @return array{id: string, name: string, source_system: string, client_id: string, scopes: list<string>, transaction_type_keys: list<string>, webhook_url: string|null, webhook_enabled: bool, data_scope: list<string>, status: string, secret_rotated_at: string|null, revoked_at: string|null, created_at: string|null, lock_version: int, webhook_secret: string}
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new IntegrationClientResource($this->resource))->toArray($request),
            // Shown once. Store it now; the receiver verifies every webhook with it.
            'webhook_secret' => $this->secret,
        ];
    }
}
