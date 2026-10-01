<?php

namespace App\Modules\IdentityAccess\Domain;

enum MembershipStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
