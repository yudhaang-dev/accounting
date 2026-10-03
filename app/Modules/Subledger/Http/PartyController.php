<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Application\ManageParties;
use App\Modules\Subledger\Domain\Party;
use App\Modules\Subledger\Domain\PartyExternalRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartyController
{
    /**
     * Parties of the company, ordered by name. Filters: `filter[status]`, `filter[role]`
     * (`customer` or `supplier`), `filter[search]` (part of the name), and `filter[source_system]`
     * with `filter[external_ref]` to find the party a source system refers to. `per_page`
     * defaults to 50, at most 200.
     */
    public function index(ListPartiesRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $parties = Party::query()
            ->with('externalRefs')
            ->when(isset($filter['status']), fn ($query) => $query->where('status', $filter['status']))
            ->when(isset($filter['role']), fn ($query) => $query->where($filter['role'] === 'customer' ? 'is_customer' : 'is_supplier', true))
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
        return new PartyResource(Party::query()->with('externalRefs')->findOrFail($id));
    }

    /**
     * Adds a party with at least one role, optionally with the references source systems use for it.
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
