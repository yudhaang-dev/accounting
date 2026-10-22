<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One consolidation run (CO-FR-09): the packages of the perimeter for a range, read through one version of the
 * account mapping. Append-only; a change of anything it read makes a new run.
 *
 * @property string $id
 * @property string $group_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property string $label `management_rollup` while only the first stage exists (CO-FR-04)
 * @property string $status `complete` when every package is closed and covers the range, otherwise `draft`
 * @property string $presentation_currency
 * @property int|null $mapping_version
 * @property list<array<string, mixed>> $perimeter
 * @property string $package_policy
 * @property string $coverage_status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class Run extends UlidModel
{
    public const UPDATED_AT = null;

    public const MANAGEMENT_ROLLUP = 'management_rollup';

    public const COMPLETE = 'complete';

    public const DRAFT = 'draft';

    protected $table = 'co_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'perimeter' => 'array', 'mapping_version' => 'integer'];
    }

    /** @return HasMany<RunEntity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(RunEntity::class)->orderBy('company_name')->orderBy('company_id');
    }

    /** @return HasMany<RunBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(RunBalance::class);
    }
}
