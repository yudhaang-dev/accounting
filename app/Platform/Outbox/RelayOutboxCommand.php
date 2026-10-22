<?php

namespace App\Platform\Outbox;

use Illuminate\Console\Command;

class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay {--keep-days=7 : Days to keep the rows that were relayed}';

    protected $description = 'Relays the due rows of the outbox and deletes the old ones that were relayed (API-TR-25)';

    public function handle(Outbox $outbox): int
    {
        $relayed = $outbox->relay();
        $pruned = $outbox->prune((int) $this->option('keep-days'));

        $this->info("Relayed {$relayed}, pruned {$pruned}.");

        return self::SUCCESS;
    }
}
