<?php

namespace App\Modules\Subledger\Domain;

enum OpenItemStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Reversed = 'reversed';
}
