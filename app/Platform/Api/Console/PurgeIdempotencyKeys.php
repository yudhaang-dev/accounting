<?php

namespace App\Platform\Api\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('idempotency:purge')]
#[Description('Delete expired idempotency keys')]
class PurgeIdempotencyKeys extends Command
{
    public function handle(): int
    {
        $deleted = DB::table('pf_idempotency_keys')->where('expires_at', '<=', now())->delete();

        $this->info("Deleted {$deleted} expired idempotency keys.");

        return self::SUCCESS;
    }
}
