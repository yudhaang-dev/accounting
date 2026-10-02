<?php

namespace App\Modules\GeneralLedger\Domain;

/**
 * How a fiscal year is cut into periods (GL-FR-03): calendar months, twelve periods of 4, 4 and 5
 * weeks per quarter, or periods the user gives explicitly.
 */
enum CalendarPattern: string
{
    case Monthly = 'monthly';
    case FourFourFive = 'four_four_five';
    case Free = 'free';
}
