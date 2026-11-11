<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Workspace;

/** What accepting an invitation produced: the user it ended up with and, for a workspace invitation, the workspace joined. */
final readonly class AcceptedInvitation
{
    public function __construct(
        public User $user,
        public ?Workspace $workspace,
        public bool $accountCreated,
    ) {}
}
