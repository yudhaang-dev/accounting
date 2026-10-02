<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Domain\IntegrationClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read IntegrationClient $resource
 */
class IntegrationClientResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, source_system: string, client_id: string, scopes: list<string>, transaction_type_keys: list<string>, status: string, secret_rotated_at: string|null, revoked_at: string|null, created_at: string|null, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'source_system' => $this->resource->source_system,
            // OAuth client ID for the token request; the secret is never readable again.
            'client_id' => $this->resource->oauth_client_id,
            'scopes' => $this->resource->scopes,
            'transaction_type_keys' => $this->resource->transaction_type_keys,
            // `active` or `revoked`.
            'status' => $this->resource->status->value,
            'secret_rotated_at' => $this->resource->secret_rotated_at?->toIso8601String(),
            'revoked_at' => $this->resource->revoked_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
