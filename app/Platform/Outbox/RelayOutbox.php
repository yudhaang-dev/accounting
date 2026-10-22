<?php

namespace App\Platform\Outbox;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Relays the outbox right after the transaction that filled it; the scheduled `outbox:relay` is the safety net.
 */
final class RelayOutbox implements ShouldQueue
{
    use Queueable;

    public function handle(Outbox $outbox): void
    {
        $outbox->relay();
    }
}
