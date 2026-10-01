<?php

namespace App\Platform\Retention;

use App\Platform\Audit\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Retention period per company (X-FR-05, P-01): configured default, optionally extended per company.
 * Nothing here deletes data; it only answers "how long" and "has it expired", and opens the one
 * transaction in which a purge of expired records is allowed to bypass the delete guard.
 */
final class Retention
{
    public function __construct(private readonly Audit $audit) {}

    public function defaultYears(): int
    {
        return (int) config('retention.default_years');
    }

    public function minimumYears(): int
    {
        return (int) config('retention.minimum_years');
    }

    public function yearsFor(string $companyId): int
    {
        $years = DB::table('pf_retention_policies')->where('company_id', $companyId)->value('years');

        return $years === null ? $this->defaultYears() : (int) $years;
    }

    /** Sets a company's period. It may be longer than the minimum, never shorter. */
    public function define(string $companyId, int $years): void
    {
        if ($years < $this->minimumYears()) {
            throw new InvalidArgumentException("Retention must be at least {$this->minimumYears()} years.");
        }

        $before = $this->yearsFor($companyId);

        DB::table('pf_retention_policies')->upsert(
            ['company_id' => $companyId, 'years' => $years, 'created_at' => now(), 'updated_at' => now()],
            ['company_id'],
            ['years', 'updated_at'],
        );

        $this->audit->record(
            event: 'retention.policy_changed',
            description: 'Retention period changed',
            before: ['years' => $before],
            after: ['years' => $years],
            companyId: $companyId,
        );
    }

    /** First day on which a record dated `$recordDate` may be deleted. */
    public function retainUntil(string $companyId, CarbonInterface $recordDate): CarbonImmutable
    {
        return CarbonImmutable::instance($recordDate)->addYears($this->yearsFor($companyId));
    }

    public function hasExpired(string $companyId, CarbonInterface $recordDate, ?CarbonInterface $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->retainUntil($companyId, $recordDate));
    }

    /**
     * Runs `$purge` in a transaction where hard deletes are permitted. Only a retention purge may call
     * this, and only for records that `hasExpired()`; nothing else in the code base may.
     *
     * @template T
     *
     * @param  Closure(): T  $purge
     * @return T
     */
    public function purging(Closure $purge): mixed
    {
        return DB::transaction(function () use ($purge) {
            DB::statement("SELECT set_config('app.retention_purge', 'on', true)");

            try {
                return $purge();
            } finally {
                // Transaction-local settings last until the OUTERMOST transaction ends, so switch it
                // off explicitly: the permission must not outlive the purge when nested in another.
                DB::statement("SELECT set_config('app.retention_purge', 'off', true)");
            }
        });
    }
}
