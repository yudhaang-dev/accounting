<?php

namespace App\Platform\Audit;

use App\Support\Modules\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pf_';
    }

    public function register(): void
    {
        $this->app->singleton(Audit::class);
    }
}
