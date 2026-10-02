<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Which transaction currencies an account accepts (GL-FR-02): the functional currency only, one
 * specific foreign currency, or any.
 */
enum CurrencyRestriction: string
{
    case FunctionalOnly = 'functional_only';
    case Specific = 'specific';
    case Any = 'any';
}
