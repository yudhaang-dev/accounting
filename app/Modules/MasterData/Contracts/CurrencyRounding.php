<?php

namespace App\Modules\MasterData\Contracts;

use Brick\Math\RoundingMode;

/**
 * How amounts of a currency are rounded to its scale (MD-FR-06).
 */
enum CurrencyRounding: string
{
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';
    case Up = 'up';
    case Down = 'down';

    public function brick(): RoundingMode
    {
        return match ($this) {
            self::HalfUp => RoundingMode::HalfUp,
            self::HalfEven => RoundingMode::HalfEven,
            self::Up => RoundingMode::Up,
            self::Down => RoundingMode::Down,
        };
    }
}
