<?php

namespace App\Modules\Subledger;

use App\Modules\Subledger\Application\EloquentControlAccounts;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Support\Modules\ModuleServiceProvider;

class SubledgerServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'sl_';
    }

    public function register(): void
    {
        $this->app->bind(ControlAccounts::class, EloquentControlAccounts::class);
    }
}
