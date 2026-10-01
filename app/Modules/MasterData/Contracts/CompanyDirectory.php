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

    /**
     * Changes the profile. The functional currency can only change while the company is in `setup`.
     *
     * Fails with ApiException VERSION_CONFLICT, FUNCTIONAL_CURRENCY_LOCKED.
     */
    public function update(string $companyId, CompanyChanges $changes, int $expectedVersion): CompanyData;

    /**
     * `setup` to `active`. Prerequisites are checked by the application layer before this is called.
     *
     * Fails with ApiException INVALID_STATE_TRANSITION.
     */
    public function activate(string $companyId): CompanyData;

    /**
     * `active` to `inactive`: the company data becomes read-only.
     *
     * Fails with ApiException INVALID_STATE_TRANSITION.
     */
    public function deactivate(string $companyId): CompanyData;

    /**
     * `inactive` back to `active`.
     *
     * Fails with ApiException INVALID_STATE_TRANSITION.
     */
    public function reactivate(string $companyId): CompanyData;
}
