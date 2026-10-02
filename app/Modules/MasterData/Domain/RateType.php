<?php

namespace App\Modules\MasterData\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A kind of exchange rate of the company, such as a central bank mid rate or a tax rate (MD-FR-07).
 * The `key` is stable once created; no code may branch on it (P-01).
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $label
 * @property int|null $max_age_days
 * @property DimensionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RateType extends VersionedCompanyModel
{
    protected $table = 'md_rate_types';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['key', 'label', 'max_age_days', 'status'];

    protected function casts(): array
    {
        return [
            'max_age_days' => 'integer',
            'status' => DimensionStatus::class,
        ];
    }

    /** @return HasMany<ExchangeRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    public function isActive(): bool
    {
        return $this->status === DimensionStatus::Active;
    }
}
