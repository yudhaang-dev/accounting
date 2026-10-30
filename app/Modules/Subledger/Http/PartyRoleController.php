<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\ManagePartyRoles;
use App\Modules\Subledger\Application\PartyRoleTemplates;
use App\Modules\Subledger\Domain\PartyRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartyRoleController
{
    /**
     * Party roles of the company, ordered by key. Filter: `filter[status]`.
     */
    public function index(ListPartyRolesRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('filter.status');

        return PartyRoleResource::collection(
            PartyRole::query()->when($status !== null, fn ($query) => $query->where('status', $status))->orderBy('key')->get(),
        );
    }

    /**
     * Roles the company may adopt, with a label in the language of the request. Only a suggestion until
     * the company confirms the ones it wants.
     *
     * @response array{data: list<array{key: string, label: string}>}
     */
    public function templates(PartyRoleTemplates $templates): JsonResponse
    {
        return response()->json(['data' => $templates->all()]);
    }

    /**
     * Creates the picked roles of the template. `confirmed` must be true. Fails as a whole when the
     * company already has one of the keys.
     */
    public function import(ImportPartyRolesRequest $request, ManagePartyRoles $roles): JsonResponse
    {
        /** @var list<string> $keys */
        $keys = $request->validated('keys');

        return PartyRoleResource::collection(collect($roles->import($keys)))->response()->setStatusCode(201);
    }

    /**
     * Adds a role with a key of its own. The key never changes afterwards.
     */
    public function store(CreatePartyRoleRequest $request, ManagePartyRoles $roles): JsonResponse
    {
        return (new PartyRoleResource($roles->create($request->string('key')->toString(), $request->string('label')->toString())))->response()->setStatusCode(201);
    }

    /**
     * Changes the label or deactivates and reactivates the role with `status`. `lock_version` is required.
     */
    public function update(UpdatePartyRoleRequest $request, ManagePartyRoles $roles, string $companyId, string $id): PartyRoleResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new PartyRoleResource($roles->update($id, $data, $expected));
    }
}
