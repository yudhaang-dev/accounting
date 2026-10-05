<?php

namespace App\Modules\Planning\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The projection of one fiscal year, kept in named versions (PL-FR-01). A plan never becomes a journal.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $fiscal_year_id
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends VersionedCompanyModel
{
    protected $table = 'pl_plans';

    protected $fillable = ['name', 'fiscal_year_id', 'description'];

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }
}
