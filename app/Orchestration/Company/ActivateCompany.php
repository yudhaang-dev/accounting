<?php

namespace App\Orchestration\Company;

use App\Modules\MasterData\Contracts\CompanyData;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Moves a company from `setup` to `active` once every prerequisite of the other modules is met
 * (MD-FR-01). Nothing changes when one is missing.
 */
final class ActivateCompany
{
    public const REQUIREMENTS_TAG = 'company.activation_requirements';

    /** @param  iterable<ActivationRequirement>  $requirements */
    public function __construct(
        private readonly CompanyDirectory $companies,
        private readonly iterable $requirements,
    ) {}

    public function __invoke(string $companyId): CompanyData
    {
        return DB::transaction(function () use ($companyId): CompanyData {
            $missing = [];

            foreach ($this->requirements as $requirement) {
                if (! $requirement->isMet($companyId)) {
                    $missing[$requirement->key()] = [$requirement->message()];
                }
            }

            if ($missing !== []) {
                throw new ApiException(ErrorCode::CompanyNotReady, errors: $missing);
            }

            return $this->companies->activate($companyId);
        });
    }
}
