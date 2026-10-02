<?php

namespace App\Modules\MasterData\Domain;

use App\Modules\MasterData\Database\Factories\DimensionValueFactory;
use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A value of a dimension type (MD-FR-03). A value that was ever used is only deactivated, never
 * deleted; relations use the ID, so renaming does not touch history.
 *
 * @property string $id
 * @property string $company_id
 * @property string $dimension_type_id
 * @property string $code
 * @property string $name
 * @property string|null $parent_id
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property DimensionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(DimensionValueFactory::class)]
class DimensionValue extends VersionedCompanyModel
{
    /** @use HasFactory<DimensionValueFactory> */
    use HasFactory;

    protected $table = 'md_dimension_values';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['dimension_type_id', 'code', 'name', 'parent_id', 'valid_from', 'valid_to', 'status'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'status' => DimensionStatus::class,
        ];
    }

    /** @return BelongsTo<DimensionType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DimensionType::class, 'dimension_type_id');
    }

    /** @return BelongsTo<DimensionValue, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<DimensionValue, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isActive(): bool
    {
        return $this->status === DimensionStatus::Active;
    }
}
