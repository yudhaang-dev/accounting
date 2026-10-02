<?php

namespace App\Modules\IdentityAccess\Http;

use App\Models\User;
use App\Modules\IdentityAccess\Application\ManageMemberships;
use App\Modules\IdentityAccess\Application\MembershipRoles;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\MembershipStatus;
use App\Support\Company\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MembershipController
{
    /**
     * Members of the workspace with their roles, ordered by name. Filter: `filter[status]` is
     * `active`, `revoked` or `expired`. `per_page` defaults to 50, at most 200.
     */
    public function index(ListMembershipsRequest $request, ManageMemberships $memberships, MembershipRoles $roles, CompanyContext $company): AnonymousResourceCollection
    {
        $status = $request->validated('filter.status');

        $page = $memberships->query()
            ->with('user')
            ->when($status === 'active', fn ($query) => $query->grantingAccess())
            ->when($status === 'revoked', fn ($query) => $query->where('status', MembershipStatus::Revoked->value))
            ->when($status === 'expired', fn ($query) => $query
                ->where('status', MembershipStatus::Active->value)->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->orderBy(User::query()->select('name')->whereColumn('users.id', 'ia_memberships.user_id'))
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        $roles->attach($company->require(), $page->items());

        return MembershipResource::collection($page);
    }

    public function show(ManageMemberships $memberships, MembershipRoles $roles, CompanyContext $company, string $companyId, string $id): MembershipResource
    {
        $membership = $memberships->query()->with('user')->findOrFail($id);
        $roles->attach($company->require(), [$membership]);

        return new MembershipResource($membership);
    }

    /**
     * Changes the expiry date or replaces the roles of a member. `lock_version` is required.
     */
    public function update(UpdateMembershipRequest $request, ManageMemberships $memberships, MembershipRoles $roles, CompanyContext $company, string $companyId, string $id): MembershipResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return $this->present($memberships->update($id, $data, $expected), $roles, $company);
    }

    /**
     * Revokes the membership: access stops from the next request; audit records stay. You cannot
     * revoke your own membership.
     */
    public function revoke(Request $request, ManageMemberships $memberships, MembershipRoles $roles, CompanyContext $company, string $companyId, string $id): MembershipResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->present($memberships->revoke($id, $user->id), $roles, $company);
    }

    private function present(Membership $membership, MembershipRoles $roles, CompanyContext $company): MembershipResource
    {
        $membership->load('user');
        $roles->attach($company->require(), [$membership]);

        return new MembershipResource($membership);
    }
}
