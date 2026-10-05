<?php

namespace App\Modules\Planning\Http;

use App\Models\User;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Application\ExportPlanLines;
use App\Modules\Planning\Application\ImportPlanLines;
use App\Modules\Planning\Application\ManagePlanLines;
use App\Modules\Planning\Domain\PlanLine;
use App\Modules\Planning\Domain\PlanVersion;
use App\Platform\Export\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanLineController
{
    /**
     * Lines of a version ordered by period, account and dimension set. Filters: `filter[period_id]`,
     * `filter[account_id]`. `per_page` defaults to 100, at most 500.
     */
    public function index(ListPlanLinesRequest $request, DimensionSets $dimensionSets, string $companyId, string $id): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];
        $version = PlanVersion::query()->findOrFail($id);

        $lines = PlanLine::query()
            ->where('plan_version_id', $version->id)
            ->when(isset($filter['period_id']), fn ($query) => $query->where('period_id', $filter['period_id']))
            ->when(isset($filter['account_id']), fn ($query) => $query->where('account_id', $filter['account_id']))
            ->orderBy('period_id')
            ->orderBy('account_id')
            ->orderBy('dimension_set_id')
            ->paginate($request->integer('per_page', 100))
            ->appends($request->query());

        $setIds = array_values(array_unique(array_map(fn (PlanLine $line): string => $line->dimension_set_id, $lines->items())));
        $sets = $dimensionSets->describe($companyId, $setIds);
        $lines->getCollection()->each(fn (PlanLine $line) => $line->setAttribute('dimensions', $sets[$line->dimension_set_id] ?? []));

        return PlanLineResource::collection($lines);
    }

    /**
     * Enters cells in a draft version. A cell is a period of the fiscal year of the plan, a leaf
     * income or expense account that is active, and a dimension set given as `dimensions`; a cell that
     * exists takes the new amount and note. All or nothing: any invalid row rejects the request and
     * every problem is listed under `lines.<index>.<field>`. A version that is not a draft gives 409
     * `INVALID_STATE_TRANSITION`.
     *
     * @response array{data: array{created: int, updated: int}}
     */
    public function enter(EnterPlanLinesRequest $request, ManagePlanLines $lines, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $lines->enter($id, $request->validated('lines'), $user->id)]);
    }

    /**
     * Deletes a line of a draft version.
     */
    public function destroy(Request $request, ManagePlanLines $lines, string $companyId, string $id, string $lineId): Response
    {
        /** @var User $user */
        $user = $request->user();

        $lines->delete($id, $lineId, $user->id);

        return response()->noContent();
    }

    /**
     * Enters lines from CSV into a draft version, as a multipart `file` or a `text/csv` body. Columns:
     * `period` (a date inside the period), `account_code`, `amount`, optionally `note` and one
     * `dimension.<type key>` column with value codes. At most 5000 rows. All or nothing: any invalid row
     * rejects the whole file and every problem is listed under `rows.<line>.<field>`.
     *
     * @response array{data: array{created: int, updated: int}}
     */
    public function import(Request $request, ImportPlanLines $import, string $companyId, string $id): JsonResponse
    {
        $file = $request->file('file');

        if ($file !== null) {
            $csv = $file->getContent();
        } elseif (str_contains((string) $request->header('Content-Type'), 'text/csv')) {
            $csv = $request->getContent();
        } else {
            throw ValidationException::withMessages(['file' => [__('plans.csv_required')]]);
        }

        if (strlen($csv) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => [__('plans.csv_too_large')]]);
        }

        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $import($id, $csv, $user->id)]);
    }

    /**
     * The lines of a version as CSV, in the layout that the import reads.
     */
    public function export(ExportPlanLines $export, string $companyId, string $id): StreamedResponse
    {
        $version = PlanVersion::query()->findOrFail($id);
        [$header, $rows] = $export($version);

        return CsvExport::download('plan-lines-'.$version->id.'.csv', $header, $rows);
    }

    /**
     * Copies lines into a draft version as a starting point, from another version of the same fiscal
     * year (`from_version_id`) or from what was posted in a period (`actuals_period_id` into
     * `to_period_id`). Cells that exist take the copied amounts. `skipped` counts actuals of accounts
     * that cannot be planned any more.
     *
     * @response array{data: array{created: int, updated: int, skipped: int}}
     */
    public function copy(CopyPlanLinesRequest $request, ManagePlanLines $lines, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $result = isset($data['from_version_id'])
            ? $lines->copyFromVersion($id, $data['from_version_id'], $user->id)
            : $lines->copyFromActuals($id, $data['actuals_period_id'], $data['to_period_id'], $user->id);

        return new JsonResponse(['data' => $result]);
    }
}
