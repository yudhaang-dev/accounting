<?php

namespace App\Platform\Audit;

enum AuditOutcome: string
{
    case Success = 'success';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
