<?php

namespace App\Modules\Subledger;

use App\Modules\Subledger\Application\AccountReconciliations;
use App\Modules\Subledger\Application\DocumentPosting;
use App\Modules\Subledger\Application\EloquentCashAccountDirectory;
use App\Modules\Subledger\Application\EloquentControlAccounts;
use App\Modules\Subledger\Application\EloquentPartyDirectory;
use App\Modules\Subledger\Application\PartyRoleTemplateComponent;
use App\Modules\Subledger\Application\PostingProfileTemplateComponent;
use App\Modules\Subledger\Application\RecordOpeningOpenItems;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\OpeningOpenItems;
use App\Modules\Subledger\Contracts\PartyDirectory;
use App\Modules\Subledger\Contracts\ReconciliationStatus;
use App\Modules\Subledger\Contracts\SubledgerPosting;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Template\TemplateComponent;

class SubledgerServiceProvider extends ModuleServiceProvider
{
    public static function tablePrefix(): string
    {
        return 'sl_';
    }

    public function register(): void
    {
        $this->app->bind(ControlAccounts::class, EloquentControlAccounts::class);
        $this->app->bind(PartyDirectory::class, EloquentPartyDirectory::class);
        $this->app->bind(CashAccountDirectory::class, EloquentCashAccountDirectory::class);
        $this->app->bind(SubledgerPosting::class, DocumentPosting::class);
        $this->app->bind(OpeningOpenItems::class, RecordOpeningOpenItems::class);
        $this->app->bind(ReconciliationStatus::class, AccountReconciliations::class);
        $this->app->tag([PartyRoleTemplateComponent::class, PostingProfileTemplateComponent::class], TemplateComponent::TAG);
    }
}
