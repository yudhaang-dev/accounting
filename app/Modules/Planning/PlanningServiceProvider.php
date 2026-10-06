<?php

namespace App\Modules\Planning;

use App\Modules\Planning\Application\EloquentPlanReader;
use App\Modules\Planning\Contracts\PlanReader;
use App\Support\Modules\ModuleServiceProvider;

class PlanningServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'pl_';
    }

    public function register(): void
    {
        $this->app->bind(PlanReader::class, EloquentPlanReader::class);
    }
}
