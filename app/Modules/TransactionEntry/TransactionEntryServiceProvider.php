<?php

namespace App\Modules\TransactionEntry;

use App\Support\Modules\ModuleServiceProvider;

class TransactionEntryServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'te_';
    }
}
