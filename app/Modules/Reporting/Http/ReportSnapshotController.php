<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\PublishReportSnapshot;
use App\Modules\Reporting\Domain\ReportDefinition;
use App\Modules\Reporting\Domain\ReportSnapshot;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportSnapshotController
{
    /**
     * Snapshots of the company, newest first, without their values; `definition_id` narrows them to one
     * definition. `per_page` defaults to 50, at most 200.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReportSnapshotResource::collection(
            ReportSnapshot::query()
                ->when($request->query('definition_id'), fn ($query, $id) => $query->where('definition_id', $id))
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate(min(200, max(1, $request->integer('per_page', 50))))->appends($request->query()),
        );
    }

    /**
     * A snapshot with the values it was published with: the parameters, the version of the definition
     * and of the account role mapping, the data it covered and the result as it was computed. It does
     * not change when the definition or the mapping does (RP-AC-08). 403 for a user limited to some
     * dimension values, as a snapshot is the report of the whole company.
     *
     * @response array{data: array{id: string, definition_id: string, definition_version: int, sequence: int, parameters: array<string, mixed>, mapping_versions: array<string, int|null>, data_boundary: array<string, mixed>, reason: string|null, recomputed_from_id: string|null, published_by: int|null, published_at: string, result: array<string, mixed>}}
     */
    public function show(DataScopeContext $scope, string $companyId, string $id): ReportSnapshotResource
    {
        if ($scope->current()->isRestricted()) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return ReportSnapshotResource::withResult(ReportSnapshot::query()->findOrFail($id));
    }

    /**
     * Publishes the definition as a snapshot (RP-FR-14): it runs for `from`..`to` (or `as_of`) with the
     * filters of the report, `version` of the definition (the current one by default), and the values
     * are kept. `reason` is free text. 403 for a user limited to some dimension values.
     */
    public function publish(PublishReportSnapshotRequest $request, PublishReportSnapshot $publish, DataScopeContext $scope, string $companyId, string $id): JsonResponse
    {
        $definition = ReportDefinition::query()->findOrFail($id);

        /** @var array<string, mixed> $params */
        $params = $request->validated();

        $snapshot = $publish($companyId, $definition->id, $params, $scope->current(), (int) $request->user()?->getAuthIdentifier(), $request->integer('version') ?: null, $params['reason'] ?? null);

        return ReportSnapshotResource::withResult($snapshot)->response()->setStatusCode(201);
    }

    /**
     * Recalculates a snapshot: the same parameters against the definition (its current version unless
     * `version` is given) and the account role mapping in force now. The result is a new snapshot that
     * names this one in `recomputed_from_id`; this one stays as it was (X-FR-03, X-AC-04).
     */
    public function recompute(RecomputeReportSnapshotRequest $request, PublishReportSnapshot $publish, DataScopeContext $scope, string $companyId, string $id): JsonResponse
    {
        $original = ReportSnapshot::query()->findOrFail($id);

        /** @var array<string, mixed> $params */
        $params = $original->parameters;

        $snapshot = $publish($companyId, $original->definition_id, $params, $scope->current(), (int) $request->user()?->getAuthIdentifier(), $request->integer('version') ?: null, $request->validated('reason'), $original->id);

        return ReportSnapshotResource::withResult($snapshot)->response()->setStatusCode(201);
    }
}
