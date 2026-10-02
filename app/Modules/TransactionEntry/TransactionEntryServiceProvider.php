<?php

namespace App\Modules\TransactionEntry;

use App\Modules\TransactionEntry\Application\EloquentTransactionTypeKeys;
use App\Modules\TransactionEntry\Application\EloquentUnfinishedEntries;
use App\Modules\TransactionEntry\Contracts\TransactionTypeKeys;
use App\Modules\TransactionEntry\Contracts\UnfinishedEntries;
use App\Support\Modules\ModuleServiceProvider;

class TransactionEntryServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'te_';
    }

    public function register(): void
    {
        $this->app->bind(TransactionTypeKeys::class, EloquentTransactionTypeKeys::class);
        $this->app->bind(UnfinishedEntries::class, EloquentUnfinishedEntries::class);
    }
}
