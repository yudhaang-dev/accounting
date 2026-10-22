<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\RunResult;
use App\Modules\Consolidation\Application\RunRollup;
use App\Modules\Consolidation\Domain\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunController
{
    /**
     * The runs of the group, newest first, without their lines. `per_page` defaults to 50, at most 200. Needs a
     * read grant on the group.
     *
     * @response array{data: list<array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, package_policy: string, coverage_status: string, created_by: int|null, created_at: string|null}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
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
     *
     * @response array{data: array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, package_policy: string, coverage_status: string, created_by: int|null, created_at: string|null, perimeter: list<array{member_id: string, company_id: string, effective_from: string, effective_to: string|null, approved_by: int|null, approved_at: string|null}>, entities: list<array{company_id: string, name: string, functional_currency: string, book_status: string, coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string, balanced: bool, source: array<string, mixed>}>, lines: list<array{group_account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing: string, closing_debit: string, closing_credit: string, sources: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string}>}>, exceptions: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, reason: string}>, totals: array{mapped: array<string, string>, unmapped: array<string, string>, all: array<string, string>}, balanced: bool}}
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
     * after the perimeter, the mapping or a ledger does (CO-FR-09). With `Accept: text/csv` the rows are downloaded
     * instead: one per entity account behind each group line, the line itself, the exceptions and the total (X-FR-02).
     *
     * @response array{data: array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, package_policy: string, coverage_status: string, created_by: int|null, created_at: string|null, perimeter: list<array{member_id: string, company_id: string, effective_from: string, effective_to: string|null, approved_by: int|null, approved_at: string|null}>, entities: list<array{company_id: string, name: string, functional_currency: string, book_status: string, coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string, balanced: bool, source: array<string, mixed>}>, lines: list<array{group_account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing: string, closing_debit: string, closing_credit: string, sources: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string}>}>, exceptions: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, reason: string}>, totals: array{mapped: array<string, string>, unmapped: array<string, string>, all: array<string, string>}, balanced: bool}}
     */
    public function show(Request $request, RunResult $result, string $group, string $id): JsonResponse|StreamedResponse
    {
        $data = $result(Run::query()->where('group_id', $group)->findOrFail($id));

        return RunCsv::wanted($request) ? RunCsv::download($data) : response()->json(['data' => $data]);
    }
}
