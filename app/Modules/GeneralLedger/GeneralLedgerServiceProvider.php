<?php

namespace App\Modules\GeneralLedger;

use App\Modules\GeneralLedger\Application\EloquentAccountDirectory;
use App\Modules\GeneralLedger\Application\EloquentLedgerReader;
use App\Modules\GeneralLedger\Application\EloquentPeriodDirectory;
use App\Modules\GeneralLedger\Application\EloquentPostingService;
use App\Modules\GeneralLedger\Application\EloquentPostingValidator;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\LedgerReader;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Support\Modules\ModuleServiceProvider;

class GeneralLedgerServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'gl_';
    }

    public function register(): void
    {
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(PeriodDirectory::class, EloquentPeriodDirectory::class);
        $this->app->bind(LedgerReader::class, EloquentLedgerReader::class);
        $this->app->bind(PostingValidator::class, EloquentPostingValidator::class);
        $this->app->bind(PostingService::class, EloquentPostingService::class);
    }
}
