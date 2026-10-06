<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\ReportDefinition;
use App\Modules\Reporting\Domain\ReportDefinitionVersion;
use App\Modules\Reporting\Domain\ReportSnapshot;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Access\DataScope;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a report as a snapshot (RP-FR-14): the definition runs once, and what it produced is kept
 * with the parameters, the version of the definition and of the account role mapping it read, and the
 * data it covered. Nothing is recomputed on read, so a later change of the definition or the mapping
 * leaves the snapshot as it was (RP-AC-08). A recalculation runs the same parameters against the
 * definition and mapping in force now and adds a snapshot that points at the one it replaces (X-FR-03).
 *
 * A snapshot is read by everyone who may read reports, so only a user without a data scope can publish
 * one; a limited user would otherwise publish a partial report as the report of the company (IA-FR-03).
 */
final class PublishReportSnapshot
{
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly CashEquivalents $cash,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $params  validated report parameters; `from`..`to`, or `as_of` for both
     * @param  int|null  $version  the version of the definition to run, the current one when null
     *
     * @throws ApiException FORBIDDEN for a user with a data scope
     */
    public function __invoke(string $companyId, string $definitionId, array $params, DataScope $scope, int $actorId, ?int $version = null, ?string $reason = null, ?string $recomputedFromId = null): ReportSnapshot
    {
        if ($scope->isRestricted()) {
            throw new ApiException(ErrorCode::Forbidden, (string) __('reports.snapshot_scope_restricted'));
        }

        $from = (string) ($params['from'] ?? $params['as_of'] ?? '');
        $to = (string) ($params['to'] ?? $params['as_of'] ?? '');

        return DB::transaction(function () use ($companyId, $definitionId, $params, $scope, $actorId, $version, $reason, $recomputedFromId, $from, $to): ReportSnapshot {
            // The lock serializes publishers of one definition, so the sequence has no gaps or repeats.
            $definition = ReportDefinition::query()->lockForUpdate()->findOrFail($definitionId);
            $structure = ReportDefinitionVersion::query()
                ->where('definition_id', $definition->id)
                ->where('version', $version ?? $definition->current_version)
                ->firstOrFail();

            /** @var array{basis: string, rows: list<array<string, mixed>>, columns: list<array<string, mixed>>} $layout */
            $layout = $structure->structure;
            $result = ($this->engine)($companyId, $layout, $from, $to, $scope, ReportFilter::fromParameters($params), $definition->kind);

            $snapshot = ReportSnapshot::create([
                'definition_id' => $definition->id,
                'definition_version' => $structure->version,
                'sequence' => (int) ReportSnapshot::query()->where('definition_id', $definition->id)->max('sequence') + 1,
                'parameters' => $result['parameters'],
                'mapping_versions' => [CashEquivalents::ROLE => $this->cash->current($companyId, $to)['version'] ?? null],
                'data_boundary' => [
                    'coverage_from' => $result['coverage_from'],
                    'coverage_to' => $result['coverage_to'],
                    'coverage_granularity' => $result['coverage_granularity'],
                    'coverage_status' => $result['coverage_status'],
                    'computed_at' => now()->toIso8601String(),
                ],
                'result' => $result,
                'reason' => $reason,
                'recomputed_from_id' => $recomputedFromId,
                'published_by' => $actorId,
                'published_at' => now(),
            ])->refresh();

            $this->audit->record(
                $recomputedFromId === null ? 'report_snapshot.published' : 'report_snapshot.recomputed',
                "Report {$definition->name} published as snapshot {$snapshot->sequence}.",
                $snapshot,
                after: ['definition_version' => $snapshot->definition_version, 'sequence' => $snapshot->sequence, 'recomputed_from_id' => $recomputedFromId],
                companyId: $companyId,
            );

            return $snapshot;
        });
    }
}
