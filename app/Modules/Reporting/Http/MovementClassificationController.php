<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Application\ManageMovementClassifications;
use App\Modules\Reporting\Application\MovementClassifier;
use App\Modules\Reporting\Application\ReportFilter;
use App\Modules\Reporting\Domain\MovementClassification;
use App\Modules\Reporting\Domain\MovementClassificationVersion;
use App\Support\Access\DataScopeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MovementClassificationController
{
    /**
     * Movement classifications of the company, ordered by key. `per_page` defaults to 50, at most 200.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return MovementClassificationResource::collection(
            MovementClassification::query()->orderBy('key')->paginate(min(200, max(1, $request->integer('per_page', 50))))->appends($request->query()),
        );
    }

    /**
     * A classification with the rows and rules of its current version, or of `version` when given.
     */
    public function show(ShowReportDefinitionRequest $request, string $companyId, string $id): MovementClassificationResource
    {
        $classification = MovementClassification::query()->findOrFail($id);

        return MovementClassificationResource::withVersion($classification, $this->version($classification, $request->integer('version') ?: null));
    }

    /**
     * Adds a classification as version 1. The structure has `rows` (`key` and `label`) and `rules`.
     * A rule sends movement to one of the rows when the account of the line is in its `accounts`
     * (`{type: account|parent|class}` selectors, never a code range) and, when `transaction_type_keys`
     * is not empty, the journal has one of those keys. The first matching rule wins; movement that
     * no rule matches goes to the `unclassified` row when it is run (RP-FR-03).
     */
    public function store(SaveMovementClassificationRequest $request, ManageMovementClassifications $classifications): JsonResponse
    {
        $classification = $classifications->create($request->validated(), (int) $request->user()?->getAuthIdentifier());

        return MovementClassificationResource::withVersion($classification, $this->version($classification))->response()->setStatusCode(201);
    }

    /**
     * Renames the classification and, when `structure` is given and differs, adds the next version;
     * the versions before stay as they were. `lock_version` is required.
     */
    public function update(SaveMovementClassificationRequest $request, ManageMovementClassifications $classifications, string $companyId, string $id): MovementClassificationResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        $classification = $classifications->update($id, $data, $expected, (int) $request->user()?->getAuthIdentifier());

        return MovementClassificationResource::withVersion($classification, $this->version($classification));
    }

    /**
     * Movement of `from`..`to` per row of the classification, in the functional currency, with debit,
     * credit and net (debit - credit). Movement without a matching rule is in the `unclassified` row
     * and listed in `unclassified`, so `total` always equals the movement of the ledger. The filters of
     * the trial balance apply (`account_id`, `dimensions`, `transaction_type_keys`, `source`); for a
     * user limited to some dimension values only the lines inside that scope count (IA-FR-03).
     *
     * @response array{data: array{classification: array{id: string, key: string, version: int}, parameters: array{from: string, to: string, account_id: string|null, dimensions: array<string, string>, transaction_type_keys: list<string>, source: string|null}, functional_currency: string, restricted: bool, rows: list<array{key: string, label: string, debit: string, credit: string, net: string}>, total: array{debit: string, credit: string, net: string}, unclassified: list<array{account_id: string, code: string, name: string, transaction_type_key: string|null}>}}
     */
    public function evaluate(EvaluateClassificationRequest $request, MovementClassifier $classifier, DataScopeContext $scope, string $companyId, string $id): JsonResponse
    {
        $classification = MovementClassification::query()->findOrFail($id);
        $version = $this->version($classification, $request->integer('version') ?: null);

        /** @var array<string, mixed>&array{from: string, to: string} $params */
        $params = $request->validated();

        return new JsonResponse(['data' => [
            'classification' => ['id' => $classification->id, 'key' => $classification->key, 'version' => $version->version],
            ...$classifier($companyId, $version->structure, $params['from'], $params['to'], $scope->current(), ReportFilter::fromParameters($params)),
        ]]);
    }

    private function version(MovementClassification $classification, ?int $number = null): MovementClassificationVersion
    {
        return MovementClassificationVersion::query()
            ->where('classification_id', $classification->id)
            ->where('version', $number ?? $classification->current_version)
            ->firstOrFail();
    }
}
