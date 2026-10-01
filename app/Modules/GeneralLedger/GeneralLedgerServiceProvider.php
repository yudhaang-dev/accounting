<?php

namespace App\Modules\GeneralLedger;

use App\Support\Modules\ModuleServiceProvider;

class GeneralLedgerServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'gl_';
    }
}
