<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Whether lines on an account must, may or must not carry a value of a dimension type (GL-FR-02).
 */
enum DimensionRequirement: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Forbidden = 'forbidden';
}
