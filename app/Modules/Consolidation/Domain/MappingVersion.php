<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of the account mapping of a group: the whole set of rows as it was saved (P-04).
 *
 * @property string $id
 * @property string $group_id
 * @property int $version
 * @property Carbon $effective_date
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class MappingVersion extends UlidModel
{
    public const UPDATED_AT = null;

    protected $table = 'co_mapping_versions';

    protected $fillable = ['group_id', 'version', 'effective_date', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'version' => 'integer'];
    }

    /** @return HasMany<MappingRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(MappingRow::class, 'mapping_version_id')->orderBy('company_id')->orderBy('account_code');
    }
}
