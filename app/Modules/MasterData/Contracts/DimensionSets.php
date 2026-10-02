<?php

namespace App\Modules\MasterData\Contracts;

/**
 * Dimension sets of a company (MD-FR-04, MD-FR-05, ADR-0007): a combination of dimension values
 * stored once and referenced by ID, plus the hierarchy queries used by reports and data scopes.
 */
interface DimensionSets
{
    /** ID of the empty combination (no dimension values); created on first use, always the same afterwards. */
    public function emptySet(string $companyId): string;

    /** Whether the set exists in that company. */
    public function exists(string $companyId, string $dimensionSetId): bool;

    /**
     * Resolves a combination given as dimension type key => dimension value ID (`null` means none) into
     * its set, creating the set on first use. The same combination always gives the same ID, in any
     * order, and the empty combination gives `emptySet()`. Every value must belong to its type, be
     * active and valid on `$date`, and be a leaf where the type demands it (MD-FR-04).
     *
     * Fails with ApiException DIMENSION_INVALID, with errors keyed `dimensions.<type key>`.
     *
     * @param  array<string, string|null>  $dimensions
     * @param  string  $date  `YYYY-MM-DD`, the transaction date
     */
    public function resolve(string $companyId, array $dimensions, string $date): string;

    /**
     * Same as `resolve()` for a list of value IDs; two values of one type are refused.
     *
     * Fails with ApiException DIMENSION_INVALID.
     *
     * @param  list<string>  $valueIds
     */
    public function resolveValues(string $companyId, array $valueIds, string $date): string;

    /**
     * Contents of sets as dimension type key => dimension value ID, however old the set and whatever
     * the current status of its values (MD-AC-02). Unknown sets are left out.
     *
     * @param  list<string>  $dimensionSetIds
     * @return array<string, array<string, string>> keyed by set ID
     */
    public function describe(string $companyId, array $dimensionSetIds): array;

    /**
     * IDs of all values below a value, at any depth, using the current hierarchy (MD-FR-05).
     *
     * @return list<string>
     */
    public function descendants(string $companyId, string $valueId): array;

    /**
     * IDs of the values above a value, nearest parent first (MD-FR-05).
     *
     * @return list<string>
     */
    public function ancestors(string $companyId, string $valueId): array;

    /**
     * IDs of the sets that contain, for every given type key, that value or one of its descendants.
     * Used to filter reports and data scopes by a dimension with roll-up (MD-FR-05).
     *
     * Fails with ApiException DIMENSION_INVALID for an unknown type key or value.
     *
     * @param  array<string, string>  $filters  dimension type key => dimension value ID
     * @return list<string>
     */
    public function setIdsMatching(string $companyId, array $filters): array;

    /**
     * The given values grouped by the key of their dimension type, each together with all its
     * descendants, using the current hierarchy. Builds the allowed values of a data scope (MD-FR-05).
     *
     * Fails with ApiException DIMENSION_INVALID, with errors keyed `dimensions.<value ID>`, for an unknown value.
     *
     * @param  list<string>  $valueIds
     * @return array<string, list<string>> dimension type key => value IDs
     */
    public function expandValues(string $companyId, array $valueIds): array;

    /**
     * IDs of the sets that, for every given type key, contain one of the allowed values. A set without
     * a value for one of the types is left out. Unlike `setIdsMatching()` the values are not expanded.
     *
     * @param  array<string, list<string>>  $allowed  dimension type key => allowed value IDs
     * @return list<string>
     */
    public function setIdsWithin(string $companyId, array $allowed): array;
}
