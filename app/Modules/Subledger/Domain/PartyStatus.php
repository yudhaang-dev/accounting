<?php

namespace App\Modules\Subledger\Domain;

enum PartyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
