<?php

namespace App\Modules\MasterData\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The rate of a foreign currency on an effective date: functional currency per 1 unit (MD-FR-07).
 * Append-only; a correction is a new `version` and lookups use the latest one.
 *
 * @property string $id
 * @property string $company_id
 * @property string $rate_type_id
 * @property string $currency
 * @property Carbon $effective_date
 * @property BigDecimal $rate
 * @property int $version
 * @property RateSource $source
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 */
class ExchangeRate extends CompanyScopedModel
{
    public const UPDATED_AT = null;

    public const SCALE = 12;

    protected $table = 'md_exchange_rates';

    protected $fillable = ['rate_type_id', 'currency', 'effective_date', 'rate', 'version', 'source', 'actor_type', 'actor_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Exchange rates are append-only; record a new version instead.'));
    }

    protected function casts(): array
    {
        return [
            'effective_date' => 'date:Y-m-d',
            'rate' => DecimalCast::class.':'.self::SCALE,
            'version' => 'integer',
            'source' => RateSource::class,
        ];
    }

    /** @return BelongsTo<RateType, $this> */
    public function rateType(): BelongsTo
    {
        return $this->belongsTo(RateType::class);
    }

    /** A rate without trailing zeros, but never fewer than two decimals: `16250.00`, `0.000064`. */
    public static function format(BigDecimal $rate): string
    {
        $text = (string) $rate;

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return (string) BigDecimal::of($text)->toScale(max(2, BigDecimal::of($text)->getScale()));
    }
}
