<?php

namespace App\Modules\MasterData\Domain;

use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A currency a company activated, with its own scale and rounding mode (MD-FR-06).
 *
 * @property string $id
 * @property string $company_id
 * @property string $currency
 * @property int $scale
 * @property CurrencyRounding $rounding_mode
 * @property DimensionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompanyCurrency extends VersionedCompanyModel
{
    protected $table = 'md_company_currencies';

    protected $attributes = ['status' => 'active', 'rounding_mode' => 'half_up'];

    protected $fillable = ['currency', 'scale', 'rounding_mode', 'status'];

    protected function casts(): array
    {
        return [
            'scale' => 'integer',
            'rounding_mode' => CurrencyRounding::class,
            'status' => DimensionStatus::class,
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency', 'code');
    }

    public function isActive(): bool
    {
        return $this->status === DimensionStatus::Active;
    }
}
