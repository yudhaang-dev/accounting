<?php

namespace App\Modules\MasterData\Domain;

enum DimensionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
