<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\CompanyData;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\NewCompany;
use App\Modules\MasterData\Domain\Company;
use App\Platform\Audit\Audit;

final class EloquentCompanyDirectory implements CompanyDirectory
{
    public function __construct(private readonly Audit $audit) {}

    public function create(NewCompany $company): CompanyData
    {
        $model = Company::create([
            'name' => $company->name,
            'functional_currency' => $company->functionalCurrency,
            'timezone' => $company->timezone,
            'default_locale' => $company->defaultLocale,
        ])->refresh();

        $this->audit->record(
            'company.created',
            "Company {$model->name} created.",
            $model,
            after: $model->only(['name', 'functional_currency', 'timezone', 'default_locale', 'status']),
            companyId: $model->id,
        );

        return $this->data($model);
    }

    public function find(string $companyId): ?CompanyData
    {
        $model = Company::find($companyId);

        return $model === null ? null : $this->data($model);
    }

    public function findMany(array $companyIds): array
    {
        return Company::whereIn('id', $companyIds)->get()
            ->mapWithKeys(fn (Company $company): array => [$company->id => $this->data($company)])
            ->all();
    }

    private function data(Company $company): CompanyData
    {
        return new CompanyData(
            $company->id,
            $company->name,
            $company->functional_currency,
            $company->timezone,
            $company->default_locale,
            $company->status->value,
        );
    }
}
