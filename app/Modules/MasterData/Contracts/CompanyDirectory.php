<?php

namespace App\Modules\MasterData\Contracts;

/**
 * Company profile, functional currency, time zone and status for other modules (MD-FR-01).
 */
interface CompanyDirectory
{
    /** Creates a company in status `setup`. */
    public function create(NewCompany $company): CompanyData;

    public function find(string $companyId): ?CompanyData;

    /**
     * @param  list<string>  $companyIds
     * @return array<string, CompanyData> keyed by company ID
     */
    public function findMany(array $companyIds): array;
}
