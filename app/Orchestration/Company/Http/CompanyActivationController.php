<?php

namespace App\Orchestration\Company\Http;

use App\Orchestration\Company\ActivateCompany;

class CompanyActivationController
{
    /**
     * Activates the company (`setup` to `active`). Answers 422 `COMPANY_NOT_READY` with the missing
     * prerequisites in `errors` when the chart of accounts, calendar or other setup is incomplete.
     */
    public function activate(string $companyId, ActivateCompany $activateCompany): ActivatedCompanyResource
    {
        return new ActivatedCompanyResource($activateCompany($companyId));
    }
}
