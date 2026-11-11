<?php

namespace App\Modules\IdentityAccess\Console;

use App\Modules\IdentityAccess\Application\InviteFirstOwner;
use Illuminate\Console\Command;
use RuntimeException;

class InviteOwnerCommand extends Command
{
    protected $signature = 'access:invite-owner';

    protected $description = 'Create the one-time invitation of the first owner (the address in FIRST_OWNER_EMAIL). Creates no account.';

    public function handle(InviteFirstOwner $invite): int
    {
        try {
            ['email' => $email, 'token' => $token] = $invite();
        } catch (RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $this->info("Invitation created for {$email}. Open this link once, in the browser, to choose the name and the credentials.");
        $this->line(rtrim((string) config('app.url'), '/').'/invitations/accept?token='.$token);

        return self::SUCCESS;
    }
}
