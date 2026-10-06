<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\Reporting\Domain\MovementClassification;
use App\Modules\Reporting\Domain\MovementClassificationVersion;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes movement classifications (RP-FR-03). A change of the rules never edits a
 * version: it adds the next one, so what a snapshot was computed from stays readable.
 */
final class ManageMovementClassifications
{
    public function __construct(
        private readonly Audit $audit,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  array<string, mixed>  $data  key, name, structure
     *
     * @throws ValidationException
     */
    public function create(array $data, int $actorId): MovementClassification
    {
        $structure = ClassificationStructure::normalize($data['structure'], $this->accounts->all($this->company->require()));

        return $this->guarded(fn () => DB::transaction(function () use ($data, $structure, $actorId): MovementClassification {
            $classification = MovementClassification::create(['key' => $data['key'], 'name' => $data['name'], 'current_version' => 1])->refresh();

            $this->addVersion($classification, 1, $structure, $actorId);
            $this->audit->record('movement_classification.created', "Movement classification {$classification->name} created.", $classification, after: $classification->only(['key', 'name']), companyId: $classification->company_id);

            return $classification;
        }));
    }

    /**
     * Renames the classification and, when `structure` is given and differs from the current one,
     * adds the next version.
     *
     * @param  array<string, mixed>  $data  any of name, structure
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $id, array $data, int $expectedVersion, int $actorId): MovementClassification
    {
        $structure = isset($data['structure']) ? ClassificationStructure::normalize($data['structure'], $this->accounts->all($this->company->require())) : null;

        return $this->guarded(fn () => DB::transaction(function () use ($id, $data, $structure, $expectedVersion, $actorId): MovementClassification {
            $classification = MovementClassification::query()->lockForUpdate()->findOrFail($id);
            $changes = [];

            if (isset($data['name'])) {
                $changes['name'] = $data['name'];
            }

            $current = MovementClassificationVersion::query()->where('classification_id', $classification->id)->where('version', $classification->current_version)->firstOrFail();
            $next = $structure !== null && $structure != $current->structure ? $classification->current_version + 1 : null;

            if ($next !== null) {
                $changes['current_version'] = $next;
            }

            $before = ['name' => $classification->name, 'version' => $classification->current_version];
            $classification->updateLocked($changes, $expectedVersion);

            if ($next !== null) {
                $this->addVersion($classification, $next, $structure, $actorId);
            }

            if ($changes !== []) {
                $this->audit->record('movement_classification.updated', "Movement classification {$classification->name} updated.", $classification, before: $before, after: ['name' => $classification->name, 'version' => $classification->current_version], companyId: $classification->company_id);
            }

            return $classification->refresh();
        }));
    }

    /** @param  array<string, mixed>  $structure */
    private function addVersion(MovementClassification $classification, int $version, array $structure, int $actorId): void
    {
        MovementClassificationVersion::create([
            'classification_id' => $classification->id,
            'version' => $version,
            'structure' => $structure,
            'created_by' => $actorId,
        ]);
    }

    private function guarded(callable $work): MovementClassification
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => [__('reports.key_taken')]]);
        }
    }
}
