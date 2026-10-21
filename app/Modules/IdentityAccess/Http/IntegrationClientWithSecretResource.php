<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\IntegrationClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An integration client together with its secret, for the response that creates or rotates it:
 * the only time the secret can be read (IA-FR-06, IA-AC-07).
 *
 * @property-read IntegrationClient $resource
 */
class IntegrationClientWithSecretResource extends JsonResource
{
    public function __construct(IntegrationClient $client, private readonly string $secret)
    {
        parent::__construct($client);
    }

    /**
     * @return array{id: string, name: string, source_system: string, client_id: string, scopes: list<string>, transaction_type_keys: list<string>, data_scope: list<string>, status: string, secret_rotated_at: string|null, revoked_at: string|null, created_at: string|null, lock_version: int, client_secret: string}
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new IntegrationClientResource($this->resource))->toArray($request),
            // Shown once. Store it now; only a hash is kept.
            'client_secret' => $this->secret,
        ];
    }
}
