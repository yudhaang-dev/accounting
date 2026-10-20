<?php

namespace App\Support\Ledger;

/**
 * Reacts after the ledger closed a period or a fiscal year, so a module downstream of the ledger can
 * derive its results (report snapshots, RP-FR-14) without the ledger knowing it. Implementations are
 * tagged with {@see self::TAG}. They run after the closing has been committed, and a failure in one
 * must not undo the closing, so each handles its own errors.
 */
interface ClosingListener
{
    public const TAG = 'ledger.closing_listeners';

    /** @param  string  $startDate  `YYYY-MM-DD` */
    public function periodClosed(string $companyId, string $periodId, string $startDate, string $endDate, ?int $actorId): void;

    /** @param  string  $startDate  `YYYY-MM-DD` */
    public function fiscalYearClosed(string $companyId, string $fiscalYearId, string $startDate, string $endDate, ?int $actorId): void;
}
