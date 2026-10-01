<?php

namespace App\Platform\Numbering;

use Carbon\CarbonInterface;

enum ResetPeriod: string
{
    case Never = 'never';
    case Yearly = 'yearly';
    case Monthly = 'monthly';
    case Daily = 'daily';

    /** Counter bucket for a date: numbers restart whenever the bucket changes. */
    public function bucket(CarbonInterface $date): string
    {
        return match ($this) {
            self::Never => 'all',
            self::Yearly => $date->format('Y'),
            self::Monthly => $date->format('Y-m'),
            self::Daily => $date->format('Y-m-d'),
        };
    }

    /**
     * Date tokens the pattern must contain so numbers stay unique after a reset
     * (for example monthly reset needs the year and the month in the number).
     *
     * @return list<list<string>> each inner list is a set of alternatives; one of each set is required
     */
    public function requiredTokens(): array
    {
        return match ($this) {
            self::Never => [],
            self::Yearly => [['yyyy', 'yy']],
            self::Monthly => [['yyyy', 'yy'], ['mm']],
            self::Daily => [['yyyy', 'yy'], ['mm'], ['dd']],
        };
    }
}
