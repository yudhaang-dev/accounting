<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\ManagePerimeter;
use App\Modules\Consolidation\Domain\PerimeterMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PerimeterController
{
    /** Every member of the group with its status, oldest first; members that left stay listed. */
    public function index(string $group): AnonymousResourceCollection
    {
        return PerimeterMemberResource::collection(PerimeterMember::query()->where('group_id', $group)->orderBy('created_at')->orderBy('id')->get());
    }

    /**
     * Approves the control assessment of a proposed member (CO-FR-02). Needs the approval ability on the group,
     * and the approver cannot be who proposed the member (SEGREGATION_OF_DUTIES).
     */
    public function approve(Request $request, ManagePerimeter $perimeter, string $group, string $id): PerimeterMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PerimeterMemberResource($perimeter->approve($group, $id, $user->id));
    }

    /** The member leaves the perimeter as of `effective_to`. The parent company cannot leave. */
    public function end(EndPerimeterMemberRequest $request, ManagePerimeter $perimeter, string $group, string $id): PerimeterMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PerimeterMemberResource($perimeter->end($group, $id, $request->string('effective_to')->toString(), $user->id));
    }
}
