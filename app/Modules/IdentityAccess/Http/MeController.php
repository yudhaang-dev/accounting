<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use Illuminate\Http\Request;

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
}
