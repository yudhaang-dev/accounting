<?php

namespace App\Modules\Planning\Contracts;

/**
 * Reads plan versions for `Reporting` (RP-FR-11); it never writes.
 */
interface PlanReader
{
    /** The plan, or null when the company has none with that ID. */
    public function plan(string $companyId, string $planId): ?PlanData;

    /**
     * The versions of the plan that have left draft, oldest publication first: the first is the initial
     * estimate and the last the latest revision (ADR-0017).
     *
     * @return list<PlanVersionData>
     */
    public function publishedVersions(string $companyId, string $planId): array;

    /** The version, or null when the company has none with that ID. */
    public function version(string $companyId, string $versionId): ?PlanVersionData;

    /** The version that reports compare against by default in the fiscal year (PL-FR-04), if the policy names one. */
    public function defaultVersion(string $companyId, string $fiscalYearId): ?PlanVersionData;

    /**
     * Planned amounts per account over the periods; `$dimensionSetIds` null keeps every set.
     *
     * @param  list<string>  $periodIds
     * @param  list<string>|null  $dimensionSetIds
     * @return list<PlanAmount>
     */
    public function amounts(string $companyId, string $versionId, array $periodIds, ?string $accountId = null, ?array $dimensionSetIds = null): array;
}
