<?php

use App\Modules\GeneralLedger\GeneralLedgerServiceProvider;
use App\Modules\IdentityAccess\IdentityAccessServiceProvider;
use App\Modules\MasterData\MasterDataServiceProvider;
use App\Modules\Planning\PlanningServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Subledger\SubledgerServiceProvider;
use App\Modules\TransactionEntry\TransactionEntryServiceProvider;
use App\Platform\Audit\AuditServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Support\SupportServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    SupportServiceProvider::class,
    AuditServiceProvider::class,
    MasterDataServiceProvider::class,
    IdentityAccessServiceProvider::class,
    TransactionEntryServiceProvider::class,
    SubledgerServiceProvider::class,
    GeneralLedgerServiceProvider::class,
    PlanningServiceProvider::class,
    ReportingServiceProvider::class,
];
