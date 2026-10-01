<?php

namespace App\Orchestration\Company;

use App\Modules\GeneralLedger\Contracts\PeriodDirectory;

/**
 * A company can only be activated once its fiscal calendar has fiscal years and at least one open
 * period (GL-FR-03, X-AC-03). Remembers what it found so the message can name the problem.
 */
final class FiscalCalendarRequirement implements ActivationRequirement
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(private readonly PeriodDirectory $periods) {}

    public function key(): string
    {
        return 'fiscal_calendar';
    }

    public function message(): string
    {
        return __('periods.not_ready', [
            'problems' => implode(', ', array_map(fn (string $problem): string => __("periods.problems.{$problem}"), $this->problems)),
        ]);
    }

    public function isMet(string $companyId): bool
    {
        $this->problems = $this->periods->setupProblems($companyId);

        return $this->problems === [];
    }
}
