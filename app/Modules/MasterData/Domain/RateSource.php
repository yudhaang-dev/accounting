<?php

namespace App\Modules\MasterData\Domain;

/**
 * How a rate entered the system (MD-FR-07).
 */
enum RateSource: string
{
    case Manual = 'manual';
    case Csv = 'csv';
    case Api = 'api';
}
