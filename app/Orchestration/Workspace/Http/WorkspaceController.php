<?php

namespace App\Orchestration\Workspace\Http;

use App\Models\User;
use App\Modules\MasterData\Contracts\NewCompany;
use App\Orchestration\Workspace\CreateWorkspaceWithCompany;
use Illuminate\Http\JsonResponse;

class WorkspaceController
{
    /**
     * Creates a workspace together with its company (status `setup`); the caller becomes its first member.
     */
    public function store(CreateWorkspaceRequest $request, CreateWorkspaceWithCompany $createWorkspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace = $createWorkspace($user->id, $request->string('workspace_name')->toString(), new NewCompany(
            name: $request->string('company_name')->toString(),
            functionalCurrency: $request->string('functional_currency')->toString(),
            timezone: $request->string('timezone')->toString() ?: 'Asia/Jakarta',
            defaultLocale: $request->string('default_locale')->toString() ?: config('app.locale'),
        ));

        return (new CreatedWorkspaceResource($workspace))->response()->setStatusCode(201);
    }
}
