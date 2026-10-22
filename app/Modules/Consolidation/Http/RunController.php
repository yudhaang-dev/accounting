<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\RunResult;
use App\Modules\Consolidation\Application\RunRollup;
use App\Modules\Consolidation\Domain\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunController
{
    /**
     * The runs of the group, newest first, without their lines. `per_page` defaults to 50, at most 200. Needs a
     * read grant on the group.
     */
    public function index(Request $request, RunResult $result, string $group): JsonResponse
    {
        $runs = Run::query()->where('group_id', $group)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(200, max(1, $request->integer('per_page', 50))))->appends($request->query());

        return response()->json([
            'data' => $runs->getCollection()->map(fn (Run $run): array => $result->summary($run))->values()->all(),
            'meta' => ['current_page' => $runs->currentPage(), 'per_page' => $runs->perPage(), 'total' => $runs->total(), 'last_page' => $runs->lastPage()],
        ]);
    }

    /**
     * Runs the management roll-up of the group for `from`..`to` (CO-FR-04): the packages of the entities that
     * are in the perimeter for the whole range, added up per account of the group through the version of the
     * mapping in force on `to`. Needs a run grant on the group, not access to the companies (CO-FR-01).
     *
     * The result is labelled `management_rollup` and is never `formal`. It is refused (422) when an entity has
     * another functional currency than the presentation currency, when a member joined or left inside the range,
     * and, under the package policy `reject`, when a package is not closed or does not cover the range; under
     * `draft` such a run is kept with status `draft`.
     */
    public function store(CreateRunRequest $request, RunRollup $rollup, RunResult $result): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $run = $rollup($request->route('group'), $request->string('from')->toString(), $request->string('to')->toString(), $user->id);

        return response()->json(['data' => $result($run)], 201);
    }

    /**
     * A run as it was computed: its lines per account of the group, each with the entity accounts behind it, the
     * entities and the state of their packages, and the exceptions (accounts without a mapping). It never changes
     * after the perimeter, the mapping or a ledger does (CO-FR-09).
     */
    public function show(RunResult $result, string $group, string $id): JsonResponse
    {
        return response()->json(['data' => $result(Run::query()->where('group_id', $group)->findOrFail($id))]);
    }
}
