<?php

namespace App\Modules\Subledger;

use App\Modules\Subledger\Application\DocumentPosting;
use App\Modules\Subledger\Application\EloquentCashAccountDirectory;
use App\Modules\Subledger\Application\EloquentControlAccounts;
use App\Modules\Subledger\Application\EloquentPartyDirectory;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\PartyDirectory;
use App\Modules\Subledger\Contracts\SubledgerPosting;
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
        $this->app->bind(PartyDirectory::class, EloquentPartyDirectory::class);
        $this->app->bind(CashAccountDirectory::class, EloquentCashAccountDirectory::class);
        $this->app->bind(SubledgerPosting::class, DocumentPosting::class);
    }
}
