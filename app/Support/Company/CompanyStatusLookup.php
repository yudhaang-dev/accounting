<?php

namespace App\Support\Company;

/**
 * Tells whether a company still accepts writes: `setup` and `active` do, `inactive` makes its data
 * read-only (MD-FR-01). MasterData provides the real implementation; the default binding refuses.
 */
interface CompanyStatusLookup
{
    /** False for an inactive or unknown company. */
    public function isWritable(string $companyId): bool;
}
