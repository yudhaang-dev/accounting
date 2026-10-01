<?php

use App\Modules\GeneralLedger\GeneralLedgerServiceProvider;
use App\Modules\IdentityAccess\IdentityAccessServiceProvider;
use App\Modules\MasterData\MasterDataServiceProvider;
use App\Modules\Planning\PlanningServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Subledger\SubledgerServiceProvider;
use App\Modules\TransactionEntry\TransactionEntryServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    MasterDataServiceProvider::class,
    IdentityAccessServiceProvider::class,
    TransactionEntryServiceProvider::class,
    SubledgerServiceProvider::class,
    GeneralLedgerServiceProvider::class,
    PlanningServiceProvider::class,
    ReportingServiceProvider::class,
];
