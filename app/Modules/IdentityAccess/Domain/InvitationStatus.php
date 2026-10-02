<?php

namespace App\Modules\IdentityAccess\Domain;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}
