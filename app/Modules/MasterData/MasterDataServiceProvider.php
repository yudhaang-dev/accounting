<?php

namespace App\Modules\MasterData;

use App\Support\Modules\ModuleServiceProvider;

class MasterDataServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'md_';
    }
}
