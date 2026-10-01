<?php

namespace App\Modules\Reporting;

use App\Support\Modules\ModuleServiceProvider;

class ReportingServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'rp_';
    }
}
