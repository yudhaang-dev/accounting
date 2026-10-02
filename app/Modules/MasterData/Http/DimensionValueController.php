<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Application\CreateDimensionValue;
use App\Modules\MasterData\Application\DeleteDimensionValue;
use App\Modules\MasterData\Application\SetDimensionStatus;
use App\Modules\MasterData\Application\UpdateDimensionValue;
use App\Modules\MasterData\Domain\DimensionValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class DimensionValueController
{
    /**
     * Dimension values of the company, ordered by code unless `sort` says otherwise. Filters:
     * `filter[dimension_type_id]`, `filter[status]`, `filter[parent_id]` and `filter[search]` (code
     * prefix or part of the name). `per_page` defaults to 50, at most 200.
     */
    public function index(ListDimensionValuesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated('filter', []);
        $sort = $request->validated('sort', 'code');

        $values = DimensionValue::query()
            ->withExists('children as has_children')
            ->when(isset($filters['dimension_type_id']), fn (Builder $query) => $query->where('dimension_type_id', $filters['dimension_type_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['parent_id']), fn (Builder $query) => $query->where('parent_id', $filters['parent_id']))
            ->when(isset($filters['search']), fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('code', 'ilike', $filters['search'].'%')->orWhere('name', 'ilike', '%'.$filters['search'].'%'),
            ))
            ->orderBy(ltrim($sort, '-'), Str::startsWith($sort, '-') ? 'desc' : 'asc')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return DimensionValueResource::collection($values);
    }

    public function show(string $companyId, string $id): DimensionValueResource
    {
        return new DimensionValueResource(DimensionValue::query()->findOrFail($id));
    }

    /**
     * Adds a value to an active type. The parent (hierarchical types only) must be an active value
     * of the same type. `valid_from` and `valid_to` are optional.
     */
    public function store(CreateDimensionValueRequest $request, CreateDimensionValue $createValue): JsonResponse
    {
        /** @var array{dimension_type_id: string, code: string, name: string, parent_id?: string|null, valid_from?: string|null, valid_to?: string|null} $data */
        $data = $request->validated();

        return (new DimensionValueResource($createValue($data)))->response()->setStatusCode(201);
    }

    /**
     * Changes code, name, parent or validity. `lock_version` is required; the type cannot change and
     * a parent that would form a cycle is refused.
     */
    public function update(UpdateDimensionValueRequest $request, UpdateDimensionValue $updateValue, string $companyId, string $id): DimensionValueResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new DimensionValueResource($updateValue($id, $data, $expected));
    }

    /**
     * Deactivates the value. Refused while it has active children.
     */
    public function deactivate(SetDimensionStatus $status, string $companyId, string $id): DimensionValueResource
    {
        return new DimensionValueResource($status->deactivateValue($id));
    }

    /**
     * Reactivates the value. Refused while its parent or its type is inactive.
     */
    public function reactivate(SetDimensionStatus $status, string $companyId, string $id): DimensionValueResource
    {
        return new DimensionValueResource($status->reactivateValue($id));
    }

    /**
     * Deletes a value that was never used. A value with children or that is part of a dimension set
     * can only be deactivated.
     */
    public function destroy(DeleteDimensionValue $deleteValue, string $companyId, string $id): Response
    {
        $deleteValue($id);

        return response()->noContent();
    }
}
