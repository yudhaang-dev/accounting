<?php

namespace App\Modules\TransactionEntry;

use App\Modules\TransactionEntry\Application\EloquentTransactionTypeKeys;
use App\Modules\TransactionEntry\Contracts\TransactionTypeKeys;
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
    }
}
