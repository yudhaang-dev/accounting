<?php

namespace App\Modules\GeneralLedger\Domain;

/**
 * `open` accepts postings, `closed` refuses all of them; `soft_closed` accepts only what the ledger
 * policy names (GL-FR-04).
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case SoftClosed = 'soft_closed';
    case Closed = 'closed';
}
