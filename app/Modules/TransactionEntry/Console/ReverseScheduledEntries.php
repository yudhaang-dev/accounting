<?php

namespace App\Modules\TransactionEntry\Console;

use App\Modules\TransactionEntry\Application\EntryReversals;
use Illuminate\Console\Command;

class ReverseScheduledEntries extends Command
{
    protected $signature = 'entries:reverse-scheduled';

    protected $description = 'Reverses the posted entries whose automatic reversal date has come (TE-FR-11)';

    public function handle(EntryReversals $reversals): int
    {
        $result = $reversals->runScheduled();

        $this->info("Reversed {$result['done']}, failed {$result['failed']}.");

        // A failure is visible on the entry as well; the exit code lets a scheduler alert on it.
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
