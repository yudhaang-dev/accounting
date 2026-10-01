<?php

namespace App\Platform\Audit;

use App\Support\Company\CompanyContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Records audit entries: who (user or integration client), when, what, outcome and before/after values.
 * Callers pass plain data; this class never calls back into business modules.
 */
final class Audit
{
    public function __construct(private readonly CompanyContext $company) {}

    /**
     * @param  array<string, mixed>  $before  relevant values before the change
     * @param  array<string, mixed>  $after  relevant values after the change
     * @param  array<string, mixed>  $properties  extra context (reason, source reference, ...)
     * @param  Model|null  $actor  user or integration client; defaults to the authenticated principal
     * @param  string|null  $companyId  defaults to the current company context, if any
     */
    public function record(
        string $event,
        string $description,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        array $properties = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?Model $actor = null,
        ?string $companyId = null,
    ): AuditEntry {
        $logger = activity('audit')->event($event);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        if ($before !== [] || $after !== []) {
            $logger->withChanges(['old' => $before, 'attributes' => $after]);
        }

        $logger->withProperties($properties)->tap(function (AuditEntry $entry) use ($outcome, $companyId): void {
            $entry->outcome = $outcome->value;
            $entry->company_id = $companyId ?? ($this->company->has() ? $this->company->require() : null);
        });

        $entry = $logger->log($description);

        assert($entry instanceof AuditEntry);

        return $entry;
    }
}
