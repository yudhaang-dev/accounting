<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\ManageInvitations;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class InvitationController
{
    /**
     * Invitations of the workspace, newest first. `per_page` defaults to 50, at most 200.
     */
    public function index(ListRolesRequest $request, ManageInvitations $invitations): AnonymousResourceCollection
    {
        return InvitationResource::collection(
            $invitations->query()->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($request->integer('per_page', 50))->appends($request->query()),
        );
    }

    /**
     * Emails an invitation with the given roles. `expires_at` is when the membership lapses once
     * accepted. A new invitation replaces an open one for the same address.
     */
    public function store(CreateInvitationRequest $request, ManageInvitations $invitations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{email: string, role_ids: list<string>, expires_at?: string|null, data_scope?: list<string>} $data */
        $data = $request->validated();

        return (new InvitationResource($invitations->invite($data['email'], $data['role_ids'], $data['expires_at'] ?? null, $user, $data['data_scope'] ?? [])))
            ->response()->setStatusCode(201);
    }

    /**
     * Withdraws a pending invitation.
     */
    public function revoke(ManageInvitations $invitations, string $companyId, string $id): InvitationResource
    {
        return new InvitationResource($invitations->revoke($id));
    }

    /**
     * Accepts an invitation with the token from the email; no sign-in is needed when the invited address
     * has no account yet, in which case `name`, `password` and `password_confirmation` create it (email
     * verified) and the user is signed in. When the address already has an account, that user must be
     * signed in. Answers the workspace that was joined, or 204 for the first owner's invitation, which
     * only creates the account.
     *
     * @response WorkspaceResource
     */
    public function accept(AcceptInvitationRequest $request, ManageInvitations $invitations, Workspaces $workspaces): WorkspaceResource|Response
    {
        /** @var User|null $signedIn */
        $signedIn = $request->user();
        $accepted = $invitations->accept($request->string('token')->toString(), $signedIn, [
            'name' => $request->input('name'),
            'password' => $request->input('password'),
        ]);

        if ($accepted->accountCreated) {
            Auth::guard('web')->login($accepted->user);
            $request->session()->regenerate();
        }

        if ($accepted->workspace === null) {
            return response()->noContent();
        }

        foreach ($workspaces->accessibleTo($accepted->user->id) as $workspace) {
            if ($workspace->id === $accepted->workspace->id) {
                return new WorkspaceResource($workspace);
            }
        }

        abort(404);
    }
}
