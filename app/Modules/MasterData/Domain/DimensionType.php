<?php

namespace App\Modules\MasterData\Domain;

use App\Modules\MasterData\Database\Factories\DimensionTypeFactory;
use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An analysis dimension of the company, such as branch or product line (MD-FR-02). The `key` is
 * stable once created; no code may branch on it (P-01).
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $label
 * @property bool $is_hierarchical
 * @property bool $leaf_only
 * @property DimensionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(DimensionTypeFactory::class)]
class DimensionType extends VersionedCompanyModel
{
    /** @use HasFactory<DimensionTypeFactory> */
    use HasFactory;

    protected $table = 'md_dimension_types';

    protected $attributes = ['status' => 'active', 'is_hierarchical' => false, 'leaf_only' => false];

    protected $fillable = ['key', 'label', 'is_hierarchical', 'leaf_only', 'status'];

    protected function casts(): array
    {
        return [
            'is_hierarchical' => 'boolean',
            'leaf_only' => 'boolean',
            'status' => DimensionStatus::class,
        ];
    }

    /** @return HasMany<DimensionValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(DimensionValue::class);
    }

    public function isActive(): bool
    {
        return $this->status === DimensionStatus::Active;
    }
}
