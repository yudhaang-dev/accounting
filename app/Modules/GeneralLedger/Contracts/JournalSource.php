<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Where a posting command comes from (GL-FR-05). Which sources an account accepts is decided in M4-01.
 */
enum JournalSource: string
{
    case Entry = 'entry';
    case Subledger = 'subledger';
    case System = 'system';
}
