<?php

namespace App\Modules\TransactionEntry;

use App\Modules\TransactionEntry\Application\EloquentApprovalPolicies;
use App\Modules\TransactionEntry\Application\EloquentTransactionTypeKeys;
use App\Modules\TransactionEntry\Application\EloquentUnfinishedEntries;
use App\Modules\TransactionEntry\Application\FinancialEventIntake;
use App\Modules\TransactionEntry\Console\ReverseScheduledEntries;
use App\Modules\TransactionEntry\Contracts\ApprovalPolicies;
use App\Modules\TransactionEntry\Contracts\FinancialEvents;
use App\Modules\TransactionEntry\Contracts\TransactionTypeKeys;
use App\Modules\TransactionEntry\Contracts\UnfinishedEntries;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class TransactionEntryServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Hourly, because the date of a scheduled reversal is the date in the time zone of the company.
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('entries:reverse-scheduled')->hourly());
    }

    public static function tablePrefix(): string
    {
        return 'te_';
    }

    public function register(): void
    {
        $this->commands([ReverseScheduledEntries::class]);

        $this->app->bind(ApprovalPolicies::class, EloquentApprovalPolicies::class);
        $this->app->bind(FinancialEvents::class, FinancialEventIntake::class);
        $this->app->bind(TransactionTypeKeys::class, EloquentTransactionTypeKeys::class);
        $this->app->bind(UnfinishedEntries::class, EloquentUnfinishedEntries::class);
    }
}
