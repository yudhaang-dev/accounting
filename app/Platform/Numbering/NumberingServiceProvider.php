<?php

namespace App\Platform\Numbering;

use App\Support\Modules\ModuleServiceProvider;

class NumberingServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pf_';
    }

    public function register(): void
    {
        $this->app->singleton(Numbering::class);
    }
}
