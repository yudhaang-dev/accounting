<?php

namespace App\Modules\TransactionEntry\Domain;

enum VersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
