<?php

namespace App\Modules\Planning\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named version of a plan (PL-FR-01). Only a draft changes; a published version is revised by
 * copying it into a new draft, and its values stay as they were.
 *
 * @property string $id
 * @property string $company_id
 * @property string $plan_id
 * @property string $name
 * @property PlanVersionStatus $status
 * @property string|null $origin_version_id
 * @property int|null $last_modified_by
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlanVersion extends VersionedCompanyModel
{
    protected $table = 'pl_plan_versions';

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['plan_id', 'name', 'status', 'origin_version_id', 'last_modified_by'];

    protected function casts(): array
    {
        return [
            'status' => PlanVersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<PlanLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PlanLine::class);
    }
}
