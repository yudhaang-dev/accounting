<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\ReportDefinition;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Support\Access\DataScope;
use App\Support\Ledger\ClosingListener;
use Throwable;

/**
 * Publishes the definitions that ask for it as snapshots when a period or a fiscal year closes
 * (RP-FR-14). A balance sheet reads the closing date, every other kind the whole period or year.
 * The publication runs as the system, without a data scope, as a snapshot is the report of the company.
 *
 * A definition that cannot be published (a role it needs is not mapped, say) is audited as a failure and
 * the others go on: closing the books does not wait for a report.
 */
final class PublishSnapshotsOnClose implements ClosingListener
{
    public function __construct(
        private readonly PublishReportSnapshot $publish,
        private readonly Audit $audit,
    ) {}

    public function periodClosed(string $companyId, string $periodId, string $startDate, string $endDate, ?int $actorId): void
    {
        $this->run($companyId, 'publish_on_period_close', $startDate, $endDate, $actorId, 'period_close');
    }

    public function fiscalYearClosed(string $companyId, string $fiscalYearId, string $startDate, string $endDate, ?int $actorId): void
    {
        $this->run($companyId, 'publish_on_year_close', $startDate, $endDate, $actorId, 'year_close');
    }

    private function run(string $companyId, string $flag, string $from, string $to, ?int $actorId, string $trigger): void
    {
        foreach (ReportDefinition::query()->where($flag, true)->orderBy('key')->get() as $definition) {
            $params = $definition->kind === ReportStructure::BALANCE_SHEET ? ['as_of' => $to] : ['from' => $from, 'to' => $to];

            try {
                ($this->publish)($companyId, $definition->id, $params, DataScope::unrestricted(), $actorId, reason: (string) __("reports.auto_publish_reason.{$trigger}"));
            } catch (Throwable $e) {
                $this->audit->record(
                    'report_snapshot.auto_publish_failed',
                    "Report {$definition->name} could not be published when the period or year closed.",
                    $definition,
                    properties: ['trigger' => $trigger, 'from' => $from, 'to' => $to, 'error' => $e->getMessage()],
                    outcome: AuditOutcome::Failed,
                    companyId: $companyId,
                );
            }
        }
    }
}
