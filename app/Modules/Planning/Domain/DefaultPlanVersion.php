<?php

namespace App\Modules\Planning\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * The published version that reports compare against by default in a fiscal year (PL-FR-04).
 *
 * @property string $id
 * @property string $company_id
 * @property string $fiscal_year_id
 * @property string $plan_version_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DefaultPlanVersion extends CompanyScopedModel
{
    protected $table = 'pl_default_versions';

    protected $fillable = ['fiscal_year_id', 'plan_version_id'];
}
