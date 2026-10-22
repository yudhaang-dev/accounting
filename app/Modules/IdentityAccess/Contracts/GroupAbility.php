<?php

namespace App\Modules\IdentityAccess\Contracts;

/** What a group grant lets a user do (IA-FR-07): read the group's reports, or also run consolidations. */
enum GroupAbility: string
{
    case Read = 'read';
    case Run = 'run';
}
