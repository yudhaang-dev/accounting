<?php

namespace App\Modules\MasterData\Contracts;

/**
 * Dimension sets of a company (MD-FR-04, ADR-0007): a combination of dimension values stored once
 * and referenced by ID. For now only the empty combination exists.
 */
interface DimensionSets
{
    /** ID of the empty combination (no dimension values); created on first use, always the same afterwards. */
    public function emptySet(string $companyId): string;

    /** Whether the set exists in that company. */
    public function exists(string $companyId, string $dimensionSetId): bool;
}
