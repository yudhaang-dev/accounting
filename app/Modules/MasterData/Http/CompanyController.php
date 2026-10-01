<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Contracts\CompanyChanges;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompanyController
{
    public function __construct(private readonly CompanyDirectory $companies) {}

    /**
     * The company profile and status.
     */
    public function show(string $companyId): CompanyResource
    {
        return new CompanyResource($this->companies->find($companyId) ?? throw new NotFoundHttpException);
    }

    /**
     * Changes the profile. `lock_version` is required; the functional currency can only change while
     * the company is in `setup`.
     */
    public function update(UpdateCompanyRequest $request, string $companyId): CompanyResource
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new CompanyResource($this->companies->update($companyId, new CompanyChanges($data), $expected));
    }

    /**
     * Makes the company inactive: its data becomes read-only.
     */
    public function deactivate(string $companyId): CompanyResource
    {
        return new CompanyResource($this->companies->deactivate($companyId));
    }

    /**
     * Brings an inactive company back to `active`.
     */
    public function reactivate(string $companyId): CompanyResource
    {
        return new CompanyResource($this->companies->reactivate($companyId));
    }
}
