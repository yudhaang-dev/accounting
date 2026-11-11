<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\WorkspaceCreators;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user as the SPA needs it right after login. `can_create_workspace` tells whether the
 * screen to create a workspace should be offered (D2c).
 *
 * @property-read User $resource
 */
class MeResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, email_verified: bool, two_factor_enabled: bool, can_create_workspace: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'email_verified' => $this->resource->email_verified_at !== null,
            'two_factor_enabled' => $this->resource->two_factor_confirmed_at !== null,
            'can_create_workspace' => (bool) app(WorkspaceCreators::class)->canCreate($this->resource->id),
        ];
    }
}
