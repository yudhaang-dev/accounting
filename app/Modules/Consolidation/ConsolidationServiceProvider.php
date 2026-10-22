<?php

namespace App\Modules\Consolidation;

use App\Modules\Consolidation\Application\ManageGroups;
use App\Modules\Consolidation\Contracts\Groups;
use App\Support\Modules\ModuleServiceProvider;

class ConsolidationServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'co_';
    }

    public function register(): void
    {
        $this->app->bind(Groups::class, ManageGroups::class);
    }
}
