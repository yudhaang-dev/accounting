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
     * @response array{data: array{definition: array{id: string, key: string, version: int}, parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, columns: list<array{key: string, label: string, kind: string, from: string|null, to: string|null, available: bool}>, rows: list<array{key: string, label: string, type: string, values: array<string, string|null>}>, unmapped_accounts: list<array{account_id: string, code: string, name: string}>}}
     */
    public function evaluate(EvaluateReportRequest $request, ReportEngine $engine, DataScopeContext $scope, string $companyId, string $id): JsonResponse
    {
        $definition = ReportDefinition::query()->findOrFail($id);
        $version = $this->version($definition, $request->integer('version') ?: null);

        /** @var array<string, mixed>&array{from?: string, to?: string, as_of?: string} $params */
        $params = $request->validated();
        $from = $params['from'] ?? $params['as_of'] ?? '';
        $to = $params['to'] ?? $params['as_of'] ?? '';

        /** @var array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>} $structure */
        $structure = $version->structure;

        return new JsonResponse(['data' => [
            'definition' => ['id' => $definition->id, 'key' => $definition->key, 'version' => $version->version],
            ...$engine($companyId, $structure, $from, $to, $scope->current(), ReportFilter::fromParameters($params), $definition->kind),
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
