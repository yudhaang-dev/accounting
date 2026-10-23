<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\RunResult;
use App\Modules\Consolidation\Application\RunRollup;
use App\Modules\Consolidation\Application\RunStatements;
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
     * @response array{data: list<array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, translation_policy_version: int|null, package_policy: string, elimination_tolerance: string, coverage_status: string, created_by: int|null, created_at: string|null}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
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
     * The result is labelled `management_rollup` and is not `formal`, unless `formal` is asked for: the run is then
     * refused (422, under `formal`) while an account has no mapping, an intragroup pair is an exception or a package
     * does not balance, and it is `formal` only when every package is also closed and complete; otherwise it is a draft. An entity in another functional currency
     * is translated by the translation policy and the rates of the group, and the run is refused (422) when the group
     * has no policy or a rate it needs is missing. It is also refused when a member joined or left inside the range,
     * and, under the package policy `reject`, when a package is not closed or does not cover the range; under
     * `draft` such a run is kept with status `draft`.
     *
     * What the entities hold against each other (lines that name another company of the group as counterparty) is
     * matched pair by pair (CO-FR-07): the pairs whose positions differ by no more than the `elimination_tolerance` of
     * the group are eliminated, the others are listed in `intercompany` as exceptions and nothing of them is eliminated.
     * What a matched pair still leaves is kept in `unassigned_eliminations`, never removed silently.
     *
     * @response array{data: array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, translation_policy_version: int|null, package_policy: string, elimination_tolerance: string, coverage_status: string, created_by: int|null, created_at: string|null, perimeter: list<array{member_id: string, company_id: string, effective_from: string, effective_to: string|null, approved_by: int|null, approved_at: string|null}>, entities: list<array{company_id: string, name: string, functional_currency: string, book_status: string, coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string, balanced: bool, source: array<string, mixed>, translation: array<string, mixed>|null}>, lines: list<array{group_account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing: string, closing_debit: string, closing_credit: string, sources: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string, kind: string, translation: array<string, mixed>|null}>, eliminations: list<array{match_id: string, kind: string, company_id: string|null, counterparty_company_id: string|null, account_id: string|null, code: string|null, name: string|null, class: string|null, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string}>}>, exceptions: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, kind: string, translation: array<string, mixed>|null, reason: string}>, intercompany: list<array{id: string, kind: string, company_a_id: string, company_b_id: string, amount_a: string, amount_b: string, difference: string, status: string, reason: string|null, positions: list<array<string, mixed>>}>, unassigned_eliminations: list<array{match_id: string, kind: string, company_id: string|null, counterparty_company_id: string|null, account_id: string|null, code: string|null, name: string|null, class: string|null, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string}>, totals: array{eliminations: array<string, string>, mapped: array<string, string>, unmapped: array<string, string>, all: array<string, string>}, balanced: bool}}
     */
    public function store(CreateRunRequest $request, RunRollup $rollup, RunResult $result): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $run = $rollup($request->route('group'), $request->string('from')->toString(), $request->string('to')->toString(), $user->id, $request->boolean('formal'));

        return response()->json(['data' => $result($run)], 201);
    }

    /**
     * A run as it was computed: its lines per account of the group, each with the entity accounts behind it, the
     * entities and the state of their packages, and the exceptions (accounts without a mapping). It never changes
     * after the perimeter, the mapping or a ledger does (CO-FR-09). With `Accept: text/csv` the rows are downloaded
     * instead: one per entity account behind each group line, the line itself, the exceptions and the total (X-FR-02).
     *
     * @response array{data: array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, translation_policy_version: int|null, package_policy: string, elimination_tolerance: string, coverage_status: string, created_by: int|null, created_at: string|null, perimeter: list<array{member_id: string, company_id: string, effective_from: string, effective_to: string|null, approved_by: int|null, approved_at: string|null}>, entities: list<array{company_id: string, name: string, functional_currency: string, book_status: string, coverage_from: string|null, coverage_to: string|null, coverage_granularity: string, coverage_status: string, balanced: bool, source: array<string, mixed>, translation: array<string, mixed>|null}>, lines: list<array{group_account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing: string, closing_debit: string, closing_credit: string, sources: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string, kind: string, translation: array<string, mixed>|null}>, eliminations: list<array{match_id: string, kind: string, company_id: string|null, counterparty_company_id: string|null, account_id: string|null, code: string|null, name: string|null, class: string|null, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string}>}>, exceptions: list<array{company_id: string, account_id: string, code: string, name: string, class: string, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, kind: string, translation: array<string, mixed>|null, reason: string}>, intercompany: list<array{id: string, kind: string, company_a_id: string, company_b_id: string, amount_a: string, amount_b: string, difference: string, status: string, reason: string|null, positions: list<array<string, mixed>>}>, unassigned_eliminations: list<array{match_id: string, kind: string, company_id: string|null, counterparty_company_id: string|null, account_id: string|null, code: string|null, name: string|null, class: string|null, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, closing: string}>, totals: array{eliminations: array<string, string>, mapped: array<string, string>, unmapped: array<string, string>, all: array<string, string>}, balanced: bool}}
     */
    public function show(Request $request, RunResult $result, string $group, string $id): JsonResponse|StreamedResponse
    {
        $data = $result(Run::query()->where('group_id', $group)->findOrFail($id));

        return RunCsv::wanted($request) ? RunCsv::download($data) : response()->json(['data' => $data]);
    }

    /**
     * The group statements of a run (CO-FR-10): the balance sheet and the income statement over the accounts of the
     * group, the report of eliminations and adjustments, and the reconciliation of each entity to the group total.
     * They are read from the run, so they carry its label and say whether it is `formal`; a draft is never taken for
     * a formal statement. `cash_flow` is null: there is no cash flow classification at group level. With
     * `Accept: text/csv` the rows are downloaded instead (X-FR-02). Needs a read grant on the group.
     *
     * @response array{data: array{id: string, group_id: string, from: string, to: string, cutoff_date: string, label: string, formal: bool, status: string, presentation_currency: string, mapping_version: int|null, translation_policy_version: int|null, package_policy: string, elimination_tolerance: string, coverage_status: string, created_by: int|null, created_at: string|null, balance_sheet: array{assets: array{lines: list<array{group_account_id: string, code: string, name: string, amount: string}>, total: string}, liabilities: array{lines: list<array{group_account_id: string, code: string, name: string, amount: string}>, total: string}, equity: array{lines: list<array{group_account_id: string, code: string, name: string, amount: string}>, total: string}, current_year_earnings: string, total_liabilities_and_equity: string, difference: string, balanced: bool}, income_statement: array{income: array{lines: list<array{group_account_id: string, code: string, name: string, amount: string}>, total: string}, expenses: array{lines: list<array{group_account_id: string, code: string, name: string, amount: string}>, total: string}, net_result: string}, eliminations: array{pairs: list<array<string, mixed>>, eliminations: list<array<string, mixed>>, adjustments: list<array<string, mixed>>, total_eliminated: array<string, string>, total_adjusted: array<string, string>}, reconciliation: array{entities: list<array{company_id: string, name: string, functional_currency: string, book_status: string, coverage_status: string, accounts: string, translation_difference: string, eliminations: string, contribution: string}>, elimination_differences: string, adjustments: string, total: string, run_total: string}, cash_flow: null}}
     */
    public function statements(Request $request, RunStatements $statements, string $group, string $id): JsonResponse|StreamedResponse
    {
        $data = $statements(Run::query()->where('group_id', $group)->findOrFail($id));

        return RunCsv::wanted($request) ? StatementsCsv::download($data) : response()->json(['data' => $data]);
    }
}
