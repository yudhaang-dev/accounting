<?php

namespace App\Modules\GeneralLedger\Domain;

/**
 * How a fiscal year is cut into periods. Only calendar months for now; 4-4-5 and free ranges come
 * with M4-02.
 */
enum CalendarPattern: string
{
    case Monthly = 'monthly';
}
