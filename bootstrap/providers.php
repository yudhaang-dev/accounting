<?php

use App\Modules\GeneralLedger\GeneralLedgerServiceProvider;
use App\Modules\IdentityAccess\IdentityAccessServiceProvider;
use App\Modules\MasterData\MasterDataServiceProvider;
use App\Modules\Planning\PlanningServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Subledger\SubledgerServiceProvider;
use App\Modules\TransactionEntry\TransactionEntryServiceProvider;
use App\Platform\Api\ApiServiceProvider;
use App\Platform\Audit\AuditServiceProvider;
use App\Platform\Localization\LocalizationServiceProvider;
use App\Platform\Numbering\NumberingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Support\SupportServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    SupportServiceProvider::class,
    ApiServiceProvider::class,
    AuditServiceProvider::class,
    NumberingServiceProvider::class,
    LocalizationServiceProvider::class,
    MasterDataServiceProvider::class,
    IdentityAccessServiceProvider::class,
    TransactionEntryServiceProvider::class,
    SubledgerServiceProvider::class,
    GeneralLedgerServiceProvider::class,
    PlanningServiceProvider::class,
    ReportingServiceProvider::class,
];
