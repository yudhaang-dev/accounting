<?php

namespace App\Modules\Planning\Domain;

enum PlanVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
