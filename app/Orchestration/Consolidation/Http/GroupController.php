<?php

namespace App\Orchestration\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Contracts\Groups;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Orchestration\Consolidation\CreateGroup;
use App\Orchestration\Consolidation\ProposeGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupController
{
    /** The groups the user holds a grant on, by name. Membership of workspaces does not list a group (IA-FR-07). */
    public function index(Request $request, Groups $groups, GroupGrants $grants): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return GroupDataResource::collection($groups->findMany($grants->groupsOf($user->id)));
    }

    /**
     * Creates a group with its parent company as the first member, and gives the creator the grants to run
     * it and approve its perimeter. 403 unless the user holds `consolidation.join` in the parent company.
     */
    public function store(CreateGroupRequest $request, CreateGroup $createGroup): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $group = $createGroup($user->id, $request->string('name')->toString(), $request->string('parent_company_id')->toString(), $request->string('presentation_currency')->toString());

        return (new GroupDataResource($group))->response()->setStatusCode(201);
    }

    /**
     * Proposes a company as a member, with the reasoning behind the assessment of control. It counts once
     * someone with the approval ability, other than the proposer, approves it. 403 unless the user holds
     * `consolidation.join` in that company.
     */
    public function propose(ProposeMemberRequest $request, ProposeGroupMember $propose, string $group): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $member = $propose($user->id, $group, $request->string('company_id')->toString(), $request->string('control_basis')->toString(), $request->string('effective_from')->toString());

        return response()->json(['data' => ['id' => $member->id, 'company_id' => $member->companyId, 'status' => $member->status]], 201);
    }
}
