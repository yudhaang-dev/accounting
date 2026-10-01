<?php

namespace App\Modules\IdentityAccess\Console;

use App\Modules\IdentityAccess\Application\SyncPermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'access:sync-permissions';

    protected $description = 'Mirror the system permission catalog into the permissions table (run on deploy).';

    public function handle(SyncPermissionCatalog $sync): int
    {
        $added = $sync();

        $this->info($added === 0 ? 'Permission catalog is up to date.' : "Added {$added} permission(s).");

        return self::SUCCESS;
    }
}
