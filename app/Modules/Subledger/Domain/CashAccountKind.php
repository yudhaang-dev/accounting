<?php

namespace App\Modules\Subledger\Domain;

enum CashAccountKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
}
