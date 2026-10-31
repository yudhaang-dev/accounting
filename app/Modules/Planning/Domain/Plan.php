<?php

namespace App\Modules\Planning\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The projection of one activity or year over its own period, kept in named versions (PL-FR-01,
 * ADR-0017). The scope, when there is one, is the dimension value whose ledger the plan is measured
 * against. A plan never becomes a journal.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string|null $objective
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $scope_dimension_value_id
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends VersionedCompanyModel
{
    protected $table = 'pl_plans';

    protected $fillable = ['name', 'objective', 'starts_on', 'ends_on', 'scope_dimension_value_id', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }
}
