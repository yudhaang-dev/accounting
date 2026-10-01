<?php

namespace App\Platform\Retention;

use App\Support\Modules\ModuleServiceProvider;

class RetentionServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pf_';
    }

    public function register(): void
    {
        $this->app->singleton(Retention::class);
    }
}
