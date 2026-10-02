<?php

namespace App\Modules\IdentityAccess\Http;

use App\Modules\IdentityAccess\Application\ManageRoles;
use App\Modules\IdentityAccess\Domain\Role;
use App\Support\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController
{
    /**
     * Roles of the company with their permissions, ordered by name. Filter: `filter[is_active]`.
     * `per_page` defaults to 50, at most 200.
     */
    public function index(ListRolesRequest $request, CompanyContext $company): AnonymousResourceCollection
    {
        $active = $request->validated('filter.is_active');

        $roles = Role::query()
            ->where('company_id', $company->require())
            ->when($active !== null, fn ($query) => $query->where('is_active', $request->boolean('filter.is_active')))
            ->with('permissions')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return RoleResource::collection($roles);
    }

    public function show(CompanyContext $company, string $companyId, string $id): RoleResource
    {
        return new RoleResource(
            Role::query()->where('company_id', $company->require())->with('permissions')->findOrFail($id),
        );
    }

    /**
     * Adds a role with the given catalog permissions. Names are unique within the company.
     */
    public function store(CreateRoleRequest $request, ManageRoles $roles): JsonResponse
    {
        /** @var array{name: string, description?: string|null, permissions?: list<string>} $data */
        $data = $request->validated();

        return (new RoleResource($roles->create($data)))->response()->setStatusCode(201);
    }

    /**
     * Renames the role or replaces its permissions; holders get the change from their next request.
     * `lock_version` is required.
     */
    public function update(UpdateRoleRequest $request, ManageRoles $roles, string $companyId, string $id): RoleResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new RoleResource($roles->update($id, $data, $expected));
    }

    /**
     * Deactivates the role: it grants nothing until reactivated, and its holders keep it assigned.
     */
    public function deactivate(ManageRoles $roles, string $companyId, string $id): RoleResource
    {
        return new RoleResource($roles->deactivate($id)->load('permissions'));
    }

    /**
     * Reactivates the role.
     */
    public function reactivate(ManageRoles $roles, string $companyId, string $id): RoleResource
    {
        return new RoleResource($roles->reactivate($id)->load('permissions'));
    }
}
