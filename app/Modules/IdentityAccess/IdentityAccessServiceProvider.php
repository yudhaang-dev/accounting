<?php

namespace App\Modules\IdentityAccess;

use App\Support\Modules\ModuleServiceProvider;

class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'ia_';
    }
}
