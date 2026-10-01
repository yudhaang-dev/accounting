<?php

namespace App\Modules\Planning;

use App\Support\Modules\ModuleServiceProvider;

class PlanningServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pl_';
    }
}
