<?php

namespace App\Modules\MasterData;

use App\Modules\MasterData\Application\EloquentCompanyDirectory;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Support\Modules\ModuleServiceProvider;

class MasterDataServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'md_';
    }

    public function register(): void
    {
        $this->app->bind(CompanyDirectory::class, EloquentCompanyDirectory::class);
    }
}
