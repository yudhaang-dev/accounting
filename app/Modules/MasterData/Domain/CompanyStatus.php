<?php

namespace App\Modules\MasterData\Domain;

enum CompanyStatus: string
{
    case Setup = 'setup';
    case Active = 'active';
    case Inactive = 'inactive';
}
