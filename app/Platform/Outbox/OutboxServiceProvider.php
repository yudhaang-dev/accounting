<?php

namespace App\Platform\Outbox;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class OutboxServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pf_';
    }

    public function register(): void
    {
        $this->app->singleton(Outbox::class);
        $this->commands([RelayOutboxCommand::class]);
    }

    public function boot(): void
    {
        parent::boot();

        // The safety net for rows whose relay was lost or whose handler failed; the usual relay follows the commit.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('outbox:relay')->everyMinute()->withoutOverlapping();
        });
    }
}
