<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user as the SPA needs it right after login. Workspaces and permissions are
 * added by the workspace and access milestones (M1-01, M1-02).
 *
 * @property-read User $resource
 */
class MeResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, email_verified: bool, two_factor_enabled: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'email_verified' => $this->resource->email_verified_at !== null,
            'two_factor_enabled' => $this->resource->two_factor_confirmed_at !== null,
        ];
    }
}
