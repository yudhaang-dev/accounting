<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\ManagePerimeter;
use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PerimeterController
{
    /** Every member of the group with its status, oldest first; members that left stay listed. */
    public function index(CompanyDirectory $companies, string $group): AnonymousResourceCollection
    {
        $members = PerimeterMember::query()->where('group_id', $group)->orderBy('created_at')->orderBy('id')->get();
        $names = collect($companies->findMany(array_values(array_unique($members->map(fn (PerimeterMember $member): string => $member->company_id)->all()))))->pluck('name', 'id');

        return PerimeterMemberResource::collection($members->each(fn (PerimeterMember $member) => $member->setAttribute('company_name', $names[$member->company_id] ?? null)));
    }

    /**
     * Approves the control assessment of a proposed member (CO-FR-02). Needs the approval ability on the group,
     * and the approver cannot be who proposed the member (SEGREGATION_OF_DUTIES).
     */
    public function approve(Request $request, ManagePerimeter $perimeter, CompanyDirectory $companies, string $group, string $id): PerimeterMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PerimeterMemberResource($this->named($companies, $perimeter->approve($group, $id, $user->id)));
    }

    /** The member leaves the perimeter as of `effective_to`. The parent company cannot leave. */
    public function end(EndPerimeterMemberRequest $request, ManagePerimeter $perimeter, CompanyDirectory $companies, string $group, string $id): PerimeterMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PerimeterMemberResource($this->named($companies, $perimeter->end($group, $id, $request->string('effective_to')->toString(), $user->id)));
    }

    private function named(CompanyDirectory $companies, PerimeterMember $member): PerimeterMember
    {
        return $member->setAttribute('company_name', $companies->find($member->company_id)?->name);
    }
}
