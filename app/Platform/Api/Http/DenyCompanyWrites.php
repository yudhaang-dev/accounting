<?php

namespace App\Platform\Api\Http;

use App\Support\Company\CompanyStatusLookup;

/** Fail-closed default until MasterData binds the real lookup. */
final class DenyCompanyWrites implements CompanyStatusLookup
{
    public function isWritable(string $companyId): bool
    {
        return false;
    }
}
