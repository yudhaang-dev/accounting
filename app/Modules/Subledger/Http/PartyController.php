<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\ManageParties;
use App\Modules\Subledger\Domain\Party;
use App\Modules\Subledger\Domain\PartyExternalRef;
use App\Modules\Subledger\Domain\PartyRole;
use App\Modules\Subledger\Domain\PartyRoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartyController
{
    /**
     * Parties of the company, ordered by name. Filters: `filter[status]`, `filter[role]`
     * (a role key), `filter[roles][]` (parties holding any of the role keys), `filter[search]` (part of the name), and `filter[source_system]`
     * with `filter[external_ref]` to find the party a source system refers to. `per_page`
     * defaults to 50, at most 200.
     */
    public function index(ListPartiesRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $parties = Party::query()
            ->with('externalRefs', 'roleAssignments.role')
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->when(isset($filter['role']), fn ($query) => $query->whereIn('id', PartyRoleAssignment::query()
                ->select('party_id')
                ->whereIn('party_role_id', PartyRole::query()->select('id')->where('key', $filter['role']))))
            ->when(isset($filter['roles']), fn ($query) => $query->whereIn('id', PartyRoleAssignment::query()
                ->select('party_id')
                ->whereIn('party_role_id', PartyRole::query()->select('id')->whereIn('key', $filter['roles']))))
            ->when(isset($filter['search']), fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($filter['search'], '%_\\').'%'))
            ->when(isset($filter['source_system']), fn ($query) => $query->whereIn('id', PartyExternalRef::query()
                ->select('party_id')
                ->where('source_system', $filter['source_system'])
                ->where('external_ref', $filter['external_ref'])))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return PartyResource::collection($parties);
    }

    public function show(string $companyId, string $id): PartyResource
    {
        return new PartyResource(Party::query()->with('externalRefs', 'roleAssignments.role')->findOrFail($id));
    }

    /**
     * Adds a party with the roles it holds (keys of the party roles of the company), optionally with the references source systems use for it.
     * A reference already mapped to another party of the same source system is rejected.
     */
    public function store(CreatePartyRequest $request, ManageParties $parties): JsonResponse
    {
        return (new PartyResource($parties->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Changes a party and adds references. `lock_version` is required.
     */
    public function update(UpdatePartyRequest $request, ManageParties $parties, string $companyId, string $id): PartyResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new PartyResource($parties->update($id, $data, $expected));
    }

    /**
     * Deactivates the party. It stays resolvable by its references and keeps its history.
     */
    public function deactivate(ManageParties $parties, string $companyId, string $id): PartyResource
    {
        return new PartyResource($parties->deactivate($id));
    }

    /**
     * Reactivates the party.
     */
    public function reactivate(ManageParties $parties, string $companyId, string $id): PartyResource
    {
        return new PartyResource($parties->reactivate($id));
    }
}
