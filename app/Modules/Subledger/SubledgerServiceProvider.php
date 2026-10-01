<?php

namespace App\Modules\Subledger;

use App\Support\Modules\ModuleServiceProvider;

class SubledgerServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'sl_';
    }
}
