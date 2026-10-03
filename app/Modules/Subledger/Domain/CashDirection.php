<?php

namespace App\Modules\Subledger\Domain;

enum CashDirection: string
{
    case In = 'in';
    case Out = 'out';
}
