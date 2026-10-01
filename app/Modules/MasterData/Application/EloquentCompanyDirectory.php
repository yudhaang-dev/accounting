<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\CompanyChanges;
use App\Modules\MasterData\Contracts\CompanyData;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\NewCompany;
use App\Modules\MasterData\Domain\Company;
use App\Modules\MasterData\Domain\CompanyStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyStatusLookup;
use Illuminate\Support\Facades\DB;

final class EloquentCompanyDirectory implements CompanyDirectory, CompanyStatusLookup
{
    private const PROFILE_FIELDS = ['name', 'legal_name', 'tax_id', 'functional_currency', 'timezone', 'default_locale'];

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
            after: $model->only([...self::PROFILE_FIELDS, 'status']),
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

    public function update(string $companyId, CompanyChanges $changes, int $expectedVersion): CompanyData
    {
        return DB::transaction(function () use ($companyId, $changes, $expectedVersion): CompanyData {
            $company = $this->lock($companyId);
            $attributes = array_intersect_key($changes->attributes, array_flip(self::PROFILE_FIELDS));

            if (
                isset($attributes['functional_currency'])
                && $attributes['functional_currency'] !== $company->functional_currency
                && $company->status !== CompanyStatus::Setup
            ) {
                throw new ApiException(ErrorCode::FunctionalCurrencyLocked, errors: [
                    'functional_currency' => [ErrorCode::FunctionalCurrencyLocked->message()],
                ]);
            }

            $before = $company->only(array_keys($attributes));
            $company->updateLocked($attributes, $expectedVersion);

            if ($before !== $company->only(array_keys($attributes))) {
                $this->audit->record(
                    'company.updated',
                    "Company {$company->name} updated.",
                    $company,
                    before: $before,
                    after: $company->only(array_keys($attributes)),
                    companyId: $company->id,
                );
            }

            return $this->data($company->refresh());
        });
    }

    public function activate(string $companyId): CompanyData
    {
        return $this->transition($companyId, CompanyStatus::Setup, CompanyStatus::Active, 'company.activated');
    }

    public function deactivate(string $companyId): CompanyData
    {
        return $this->transition($companyId, CompanyStatus::Active, CompanyStatus::Inactive, 'company.deactivated');
    }

    public function reactivate(string $companyId): CompanyData
    {
        return $this->transition($companyId, CompanyStatus::Inactive, CompanyStatus::Active, 'company.reactivated');
    }

    public function isWritable(string $companyId): bool
    {
        $status = Company::query()->whereKey($companyId)->toBase()->value('status');

        return in_array($status, [CompanyStatus::Setup->value, CompanyStatus::Active->value], true);
    }

    private function transition(string $companyId, CompanyStatus $from, CompanyStatus $to, string $event): CompanyData
    {
        return DB::transaction(function () use ($companyId, $from, $to, $event): CompanyData {
            $company = $this->lock($companyId);

            if ($company->status !== $from) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $company->status = $to;
            $company->save();

            $this->audit->record(
                $event,
                "Company {$company->name} changed from {$from->value} to {$to->value}.",
                $company,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $company->id,
            );

            return $this->data($company);
        });
    }

    private function lock(string $companyId): Company
    {
        return Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
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
            $company->legal_name,
            $company->tax_id,
            $company->lock_version,
        );
    }
}
