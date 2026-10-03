<?php

namespace App\Modules\TransactionEntry\Console;

use App\Modules\TransactionEntry\Application\RecurringSchedules;
use Illuminate\Console\Command;

class RunRecurringSchedules extends Command
{
    protected $signature = 'entries:run-recurring';

    protected $description = 'Makes the entries of the recurring schedules whose date has come (TE-FR-14)';

    public function handle(RecurringSchedules $schedules): int
    {
        $result = $schedules->runDue();

        $this->info("Created {$result['created']}, failed {$result['failed']}.");

        // A failure is on the schedule as well; the exit code lets a scheduler alert on it.
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
