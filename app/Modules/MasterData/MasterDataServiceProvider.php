<?php

namespace App\Modules\MasterData;

use App\Modules\MasterData\Application\EloquentCompanyDirectory;
use App\Modules\MasterData\Application\EloquentCurrencies;
use App\Modules\MasterData\Application\EloquentDimensionSets;
use App\Modules\MasterData\Application\EloquentExchangeRates;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Support\Company\CompanyStatusLookup;
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
        $this->app->bind(DimensionSets::class, EloquentDimensionSets::class);
        $this->app->bind(Currencies::class, EloquentCurrencies::class);
        $this->app->bind(ExchangeRates::class, EloquentExchangeRates::class);
        // Replaces the fail-closed default bound by the API platform capability.
        $this->app->bind(CompanyStatusLookup::class, EloquentCompanyDirectory::class);
    }
}
