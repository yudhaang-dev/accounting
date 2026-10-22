<?php

namespace App\Modules\Consolidation\Domain;

use App\Platform\Api\Concerns\HasLockVersion;
use App\Support\Eloquent\UlidModel;
use Illuminate\Support\Carbon;

/**
 * A kind of rate of a group, such as closing, average or historical (CO-FR-06). Group-owned: a group has no
 * company, so it cannot use the rate types of a company.
 *
 * @property string $id
 * @property string $group_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RateType extends UlidModel
{
    use HasLockVersion;

    protected $table = 'co_rate_types';

    protected $fillable = ['group_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
