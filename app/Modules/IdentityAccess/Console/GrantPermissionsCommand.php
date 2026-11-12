<?php

namespace App\Modules\IdentityAccess\Console;

use App\Modules\IdentityAccess\Application\GrantPermissionsToRoles;
use Illuminate\Console\Command;
use InvalidArgumentException;

class GrantPermissionsCommand extends Command
{
    protected $signature = 'access:grant-permissions
        {role : Name of the role that gains the permissions}
        {permissions* : Permission keys from the catalog, e.g. reports.publish}
        {--company= : Only the role of this company (default: every company that has the role)}
        {--dry-run : Show what would change without changing it}';

    protected $description = 'Add catalog permissions to existing roles by name; idempotent and audited (run on deploy for new permissions).';

    public function handle(GrantPermissionsToRoles $grant): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $changes = $grant(
                (string) $this->argument('role'),
                array_values(array_map(strval(...), (array) $this->argument('permissions'))),
                $this->option('company') ?: null,
                $dryRun,
            );
        } catch (InvalidArgumentException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        foreach ($changes as $change) {
            $this->line(($dryRun ? 'Would grant ' : 'Granted ').implode(', ', $change['added'])." to {$change['role_name']} of {$change['company_id']}.");
        }

        $this->info($changes === [] ? 'Nothing to change.' : count($changes).' role(s) '.($dryRun ? 'would change.' : 'changed.'));

        return self::SUCCESS;
    }
}
