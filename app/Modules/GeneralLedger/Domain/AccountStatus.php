<?php

namespace App\Modules\GeneralLedger\Domain;

enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
