<?php

namespace App\Modules\GeneralLedger;

use App\Modules\GeneralLedger\Application\EloquentAccountDirectory;
use App\Modules\GeneralLedger\Application\EloquentPeriodDirectory;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Support\Modules\ModuleServiceProvider;

class GeneralLedgerServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'gl_';
    }

    public function register(): void
    {
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(PeriodDirectory::class, EloquentPeriodDirectory::class);
    }
}
