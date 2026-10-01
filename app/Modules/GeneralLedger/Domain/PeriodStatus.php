<?php

namespace App\Modules\GeneralLedger\Domain;

/**
 * `open` accepts postings, `closed` refuses all of them (GL-FR-04). `soft_closed` arrives with M4-02.
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
