<?php

namespace App\Support;

use App\Support\Access\DataScopeContext;
use App\Support\Company\CompanyContext;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CompanyContext::class);
        $this->app->scoped(DataScopeContext::class);
    }
}
