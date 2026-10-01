<?php

namespace App\Support\Company;

use LogicException;

/**
 * For models of company-scoped tables: every query is filtered by the current company and new
 * rows take `company_id` from the context. Bypass explicitly with `withoutGlobalScope(CompanyScope::class)`.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            $companyId = app(CompanyContext::class)->require();

            if ($model->company_id !== null && $model->company_id !== $companyId) {
                throw new LogicException('Model company_id does not match the current company context.');
            }

            $model->company_id = $companyId;
        });
    }
}
