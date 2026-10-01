<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a NUMERIC column to BigDecimal at a fixed scale. Usage: `'amount' => DecimalCast::class.':2'`.
 * Writing a value with more decimals than the scale throws instead of silently rounding.
 *
 * @implements CastsAttributes<BigDecimal, BigDecimal|string|int>
 */
final class DecimalCast implements CastsAttributes
{
    /** @var int<0, max> */
    private readonly int $scale;

    public function __construct(string|int $scale = 2)
    {
        $scale = (int) $scale;

        if ($scale < 0) {
            throw new InvalidArgumentException('Scale must not be negative.');
        }

        $this->scale = $scale;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        return BigDecimal::of($value)->toScale($this->scale, RoundingMode::Unnecessary);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return BigDecimal::of($value)->toScale($this->scale, RoundingMode::Unnecessary)->toString();
    }
}
