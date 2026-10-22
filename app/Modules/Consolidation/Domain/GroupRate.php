<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The rate of a currency on an effective date for a rate type of the group: presentation currency per 1 unit
 * (CO-FR-06). Append-only; a correction is a new `version` and lookups use the latest one.
 *
 * @property string $id
 * @property string $group_id
 * @property string $rate_type_id
 * @property string $currency
 * @property Carbon $effective_date
 * @property BigDecimal $rate
 * @property int $version
 * @property string $source
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class GroupRate extends UlidModel
{
    public const UPDATED_AT = null;

    public const SCALE = 12;

    public const MANUAL = 'manual';

    public const API = 'api';

    protected $table = 'co_group_rates';

    protected $fillable = ['group_id', 'rate_type_id', 'currency', 'effective_date', 'rate', 'version', 'source', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Group rates are append-only; record a new version instead.'));
    }

    protected function casts(): array
    {
        return [
            'effective_date' => 'date:Y-m-d',
            'rate' => DecimalCast::class.':'.self::SCALE,
            'version' => 'integer',
        ];
    }
}
