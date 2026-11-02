<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\ManageReportDefinitions;
use App\Modules\Reporting\Application\ReportEngine;
use App\Modules\Reporting\Application\ReportFilter;
use App\Modules\Reporting\Domain\ReportDefinition;
use App\Modules\Reporting\Domain\ReportDefinitionVersion;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ReportDefinitionController
{
    /**
     * Report definitions of the company, ordered by key. `per_page` defaults to 50, at most 200.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReportDefinitionResource::collection(
            ReportDefinition::query()->orderBy('key')->paginate(min(200, max(1, $request->integer('per_page', 50))))->appends($request->query()),
        );
    }

    /**
     * A definition with the structure of its current version, or of `version` when given.
     */
    public function show(ShowReportDefinitionRequest $request, string $companyId, string $id): ReportDefinitionResource
    {
        $definition = ReportDefinition::query()->findOrFail($id);

        return ReportDefinitionResource::withVersion($definition, $this->version($definition, $request->integer('version') ?: null));
    }

    /**
     * Adds a definition as version 1. The structure has `basis` (`balance`, `movement`, `opening`
     * or `closing`; the default of the rows), `rows` and `columns`. A row is a `heading`, an
     * `accounts` row that picks accounts by `{type: account|parent|class}` selectors (never by code
     * range) or a `subtotal` that adds earlier rows with sign 1 or -1. A column is a `period`, `ytd`
     * or `as_of` column, optionally shifted back with `shift: {years, months}`, or a `difference` of
     * two earlier columns. An account that no row claims shows up in the `unmapped` row when the
     * report runs.
     */
    public function store(SaveReportDefinitionRequest $request, ManageReportDefinitions $definitions): JsonResponse
    {
        $definition = $definitions->create($request->validated(), (int) $request->user()?->getAuthIdentifier());

        return ReportDefinitionResource::withVersion($definition, $this->version($definition))->response()->setStatusCode(201);
    }

    /**
     * Renames the definition and, when `structure` is given and differs, adds the next version; the
     * versions before stay as they were. `lock_version` is required.
     */
    public function update(SaveReportDefinitionRequest $request, ManageReportDefinitions $definitions, string $companyId, string $id): ReportDefinitionResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        $definition = $definitions->update($id, $data, $expected, (int) $request->user()?->getAuthIdentifier());

        return ReportDefinitionResource::withVersion($definition, $this->version($definition));
    }

    /**
     * Runs the definition for `from`..`to` (or `as_of`): every row has one value per column in the
     * functional currency, or `null` when the column is not available. Accounts that no row claims
     * are in the `unmapped` row and `unmapped_accounts`. The filters of the trial balance apply to
     * every column: `account_id`, `dimensions[<type key>]` with roll-up, `transaction_type_keys[]` and
     * `source`; without them every line counts, those without dimensions included (RP-FR-04). For a user limited to some dimension
     * values only the lines inside that scope are added up and `restricted` is true (IA-FR-03).
     *
     * A definition of kind `income_statement` is an income statement (RP-FR-07): only income and
     * expense accounts are added up, the closing journal of a closed year is left out of every
     * column, and the opening balance import is left out of `period` columns but kept in `ytd` ones.
     *
     * A definition of kind `balance_sheet` is a balance sheet (RP-FR-08): use `as_of` columns with
     * `closing` rows and one `current_year_earnings` row, which holds the closing balance of the
     * income and expense accounts that no row claims, so the result of a year that is not closed yet
     * shows once and a closed year shows it in retained earnings only. `balance_checks` has, per
     * `as_of` column, assets against liabilities and equity, with `balanced` false when they differ.
     *
     * A definition of kind `cash_flow_indirect` is a cash flow by the indirect method (RP-FR-09): it
     * is built from `movement` rows like any other (profit from income and expense, then each balance
     * sheet account group with the sign that turns its movement into cash: -1 for assets, +1 for
     * liabilities and equity) and a `cash_equivalents` row, which holds the accounts of that role
     * (422 when it is not mapped). Journals of the closing and of the opening balance import are left
     * out of movement; the import counts in the opening cash. Balance sheet accounts that no row
     * claims are in the `unmapped` row, still counted. `cash_checks` reconciles opening cash, net
     * increase and closing cash per column.
     *
     * A `plan` column (`span` `period` or `ytd`, optionally shifted) reads the plan version of its
     * `plan_version_id`, else of the `plan_version_id` parameter, else the default of the fiscal year the
     * range ends in (RP-FR-11); without a version, or without periods inside the range, it is not
     * `available` and has no values. A `difference` column that subtracts a plan column adds
     * `variances[<column key>]` to each row: `percentage` of the absolute plan (`null` when the plan is zero)
     * and `favorable`, which follows the `favorable` (`higher` or `lower`) of the row, else its sign
     * (credit: higher, debit: lower), and is `null` for a variance of zero (RP-FR-12). Cash is not a basis
     * here: it is the direct cash flow of the same period.
     *
     * The result says what part of the history it covers (RP-FR-13): `coverage_from`, `coverage_to`,
     * `coverage_granularity` (`transactions`, or `period_summary` for a range inside an imported
     * history) and `coverage_status` (`complete`, `partial` or `unavailable`), and every column has its
     * own `coverage_status`. A column the ledger cannot answer for, such as a month before the ledger
     * start date of a migrated company, is not `available` and has no values, never zero (RP-AC-10).
     * `as_of` and `ytd` columns only need their end date inside the data, as the imported balances carry
     * what came before. The trial balance, general ledger, direct cash flow and equity changes carry
     * the same four fields; the direct cash flow also needs the transaction types, so before the cutover
     * date it is only as complete as a history by transactions.
     *
     * An `accounts` or `cash_equivalents` row lists the `accounts` it claims, to follow into the ledger (X-FR-02).
     * A row whose definition sets `account_detail` also has `account_lines` (RP-FR-01): its leaf accounts in
     * code order with `level`, `is_leaf` and a value per column (`null` where the column has none), parents
     * that the row claims as headings with the subtotal of what is below them, and no account that is zero
     * in every column. The leaf lines add up to the value of the row in each column.
     *
     * With `Accept: text/csv` the rows and column values are downloaded instead, under the same permission (X-FR-02).
     *
     * @response array{data: array{definition: array{id: string, key: string, version: int}, parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, columns: list<array{key: string, label: string, kind: string, from: string|null, to: string|null, available: bool}>, rows: list<array{key: string, label: string, type: string, accounts?: list<array{account_id: string, code: string, name: string}>, account_lines?: list<array{account_id: string, code: string, name: string, level: int, is_leaf: bool, values: array<string, string|null>}>, values: array<string, string|null>}>, unmapped_accounts: list<array{account_id: string, code: string, name: string}>}}
     */
    public function evaluate(EvaluateReportRequest $request, ReportEngine $engine, DataScopeContext $scope, string $companyId, string $id): JsonResponse|Response
    {
        $definition = ReportDefinition::query()->findOrFail($id);
        $version = $this->version($definition, $request->integer('version') ?: null);

        /** @var array<string, mixed>&array{from?: string, to?: string, as_of?: string} $params */
        $params = $request->validated();
        $from = $params['from'] ?? $params['as_of'] ?? '';
        $to = $params['to'] ?? $params['as_of'] ?? '';

        /** @var array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>} $structure */
        $structure = $version->structure;

        $result = $engine($companyId, $structure, $from, $to, $scope->current(), ReportFilter::fromParameters($params), $definition->kind);

        if (ReportCsv::wanted($request)) {
            return ReportCsv::definition($definition->key, $result);
        }

        return new JsonResponse(['data' => [
            'definition' => ['id' => $definition->id, 'key' => $definition->key, 'version' => $version->version],
            ...$result,
        ]]);
    }

    private function version(ReportDefinition $definition, ?int $number = null): ReportDefinitionVersion
    {
        return ReportDefinitionVersion::query()
            ->where('definition_id', $definition->id)
            ->where('version', $number ?? $definition->current_version)
            ->firstOrFail();
    }
}
