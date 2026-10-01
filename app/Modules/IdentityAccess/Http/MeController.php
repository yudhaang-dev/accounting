<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MeController
{
    /**
     * The signed-in user. Answers 401 `UNAUTHENTICATED` when there is no session, which is how the
     * SPA learns that it must show the login screen.
     */
    public function show(Request $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeResource($user);
    }

    /**
     * Workspaces the signed-in user can access right now: active, unexpired memberships only.
     */
    public function workspaces(Request $request, Workspaces $workspaces): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return WorkspaceResource::collection($workspaces->accessibleTo($user->id));
    }
}
