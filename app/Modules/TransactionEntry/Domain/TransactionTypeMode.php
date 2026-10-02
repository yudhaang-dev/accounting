<?php

namespace App\Modules\TransactionEntry\Domain;

/** How an entry becomes a journal (TE-FR-03). */
enum TransactionTypeMode: string
{
    case Structured = 'structured';
    case FreeLines = 'free_lines';
}
