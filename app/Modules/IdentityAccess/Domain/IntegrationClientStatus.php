<?php

namespace App\Modules\IdentityAccess\Domain;

enum IntegrationClientStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
