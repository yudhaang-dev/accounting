<?php

namespace App\Modules\TransactionEntry\Domain;

/** Lifecycle of an entry (TE-FR-07). `posted` and `cancelled` are final; a rejected manual entry can be revised. */
enum EntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
