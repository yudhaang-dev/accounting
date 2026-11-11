<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountLifecycle;
use App\Modules\IdentityAccess\Application\ManageAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in user's own account (IA-FR-01, IA-FR-04). Everything here acts on the caller only.
 */
class AccountController
{
    /**
     * Changes the display name.
     */
    public function update(UpdateProfileRequest $request, ManageAccount $account): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeResource($account->rename($user, (string) $request->validated('name')));
    }

    /**
     * Asks to change the sign-in address. Needs the current password. The new address gets an email with a
     * token; the current address keeps working until `POST /me/email/confirm` succeeds.
     */
    public function requestEmailChange(RequestEmailChangeRequest $request, ManageAccount $account): Response
    {
        /** @var User $user */
        $user = $request->user();
        $account->requestEmailChange($user, (string) $request->validated('email'));

        return response()->noContent(202);
    }

    /**
     * Confirms the address change with the token from the email sent to the new address.
     */
    public function confirmEmailChange(ConfirmEmailChangeRequest $request, ManageAccount $account): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeResource($account->confirmEmailChange($user, (string) $request->validated('token')));
    }

    /**
     * Changes the password (needs the current one) and ends the user's other sessions.
     */
    public function changePassword(ChangePasswordRequest $request, ManageAccount $account): Response
    {
        /** @var User $user */
        $user = $request->user();
        $account->changePassword($user, (string) $request->validated('password'), $this->sessionId($request));

        return response()->noContent();
    }

    /**
     * Sessions of the user, most recently active first; `is_current` marks the one making the request.
     *
     * @response array{data: list<array{id: string, ip_address: string|null, user_agent: string|null, last_active_at: string, is_current: bool}>}
     */
    public function sessions(Request $request, ManageAccount $account): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $account->sessions($user, $this->sessionId($request))]);
    }

    /**
     * Ends one other session. The current session cannot be ended here (use logout); 404 for an unknown id.
     */
    public function endSession(Request $request, ManageAccount $account, string $id): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($account->endSession($user, $id, $this->sessionId($request)), 404);

        return response()->noContent();
    }

    /**
     * Ends every session of the user except the current one.
     */
    public function endOtherSessions(Request $request, ManageAccount $account): Response
    {
        /** @var User $user */
        $user = $request->user();
        $account->endOtherSessions($user, $this->sessionId($request));

        return response()->noContent();
    }

    /**
     * Leaves a workspace: the membership is revoked, nothing is deleted. 404 when the user has no current
     * access to it; 422 when the user is the last one who can manage its memberships.
     */
    public function leaveWorkspace(Request $request, AccountLifecycle $lifecycle, string $workspaceId): Response
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->leaveWorkspace($user, $workspaceId);

        return response()->noContent();
    }

    /**
     * Deactivates the account (needs the current password): memberships end, passkeys are removed, every
     * session ends, and the account can no longer sign in. Nothing is deleted. 422 while the user is the last
     * one who can manage the memberships of a workspace.
     */
    public function deactivate(DeactivateAccountRequest $request, AccountLifecycle $lifecycle): Response
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->deactivate($user);

        Auth::guard('web')->logout();

        return response()->noContent();
    }

    private function sessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }
}
