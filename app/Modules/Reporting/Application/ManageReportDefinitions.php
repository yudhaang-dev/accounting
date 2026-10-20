<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\Reporting\Domain\ReportDefinition;
use App\Modules\Reporting\Domain\ReportDefinitionVersion;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes report definitions (RP-FR-01). A change of the structure never edits a version:
 * it adds the next one, so what a snapshot was computed from stays readable (RP-AC-07, RP-AC-08).
 */
final class ManageReportDefinitions
{
    public function __construct(
        private readonly Audit $audit,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  array<string, mixed>  $data  key, name, kind, structure, publish_on_period_close, publish_on_year_close
     *
     * @throws ValidationException
     */
    public function create(array $data, int $actorId): ReportDefinition
    {
        $companyId = $this->company->require();
        $structure = ReportStructure::normalize($data['structure'], $this->accounts->all($companyId));

        return $this->guarded(fn () => DB::transaction(function () use ($data, $structure, $actorId): ReportDefinition {
            $definition = ReportDefinition::create([
                'key' => $data['key'],
                'name' => $data['name'],
                'kind' => $data['kind'] ?? ReportStructure::CUSTOM,
                'current_version' => 1,
                'publish_on_period_close' => (bool) ($data['publish_on_period_close'] ?? false),
                'publish_on_year_close' => (bool) ($data['publish_on_year_close'] ?? false),
            ])->refresh();

            $this->addVersion($definition, 1, $structure, $actorId);

            $this->audit->record('report_definition.created', "Report definition {$definition->name} created.", $definition, after: $definition->only(['key', 'name', 'kind', 'publish_on_period_close', 'publish_on_year_close']), companyId: $definition->company_id);

            return $definition;
        }));
    }

    /**
     * Renames the definition and, when `structure` is given and differs from the current one, adds
     * the next version.
     *
     * @param  array<string, mixed>  $data  any of name, structure, publish_on_period_close, publish_on_year_close
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $definitionId, array $data, int $expectedVersion, int $actorId): ReportDefinition
    {
        $structure = isset($data['structure']) ? ReportStructure::normalize($data['structure'], $this->accounts->all($this->company->require())) : null;

        return $this->guarded(fn () => DB::transaction(function () use ($definitionId, $data, $structure, $expectedVersion, $actorId): ReportDefinition {
            $definition = ReportDefinition::query()->lockForUpdate()->findOrFail($definitionId);
            $changes = [];

            if (isset($data['name'])) {
                $changes['name'] = $data['name'];
            }

            foreach (['publish_on_period_close', 'publish_on_year_close'] as $flag) {
                if (isset($data[$flag]) && (bool) $data[$flag] !== $definition->{$flag}) {
                    $changes[$flag] = (bool) $data[$flag];
                }
            }

            $current = ReportDefinitionVersion::query()->where('definition_id', $definition->id)->where('version', $definition->current_version)->firstOrFail();
            $next = $structure !== null && $structure != $current->structure ? $definition->current_version + 1 : null;

            if ($next !== null) {
                $changes['current_version'] = $next;
            }

            $before = ['name' => $definition->name, 'version' => $definition->current_version, 'publish_on_period_close' => $definition->publish_on_period_close, 'publish_on_year_close' => $definition->publish_on_year_close];
            $definition->updateLocked($changes, $expectedVersion);

            if ($next !== null) {
                $this->addVersion($definition, $next, $structure, $actorId);
            }

            if ($changes !== []) {
                $this->audit->record('report_definition.updated', "Report definition {$definition->name} updated.", $definition, before: $before, after: ['name' => $definition->name, 'version' => $definition->current_version, 'publish_on_period_close' => $definition->publish_on_period_close, 'publish_on_year_close' => $definition->publish_on_year_close], companyId: $definition->company_id);
            }

            return $definition->refresh();
        }));
    }

    /** @param  array<string, mixed>  $structure */
    private function addVersion(ReportDefinition $definition, int $version, array $structure, int $actorId): void
    {
        ReportDefinitionVersion::create([
            'definition_id' => $definition->id,
            'version' => $version,
            'structure' => $structure,
            'created_by' => $actorId,
        ]);
    }

    /** The key is unique per company; a concurrent request may take it between check and insert. */
    private function guarded(callable $work): ReportDefinition
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => [__('reports.key_taken')]]);
        }
    }
}
