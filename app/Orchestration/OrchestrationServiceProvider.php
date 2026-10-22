<?php

namespace App\Orchestration;

use App\Modules\TransactionEntry\Contracts\FinancialEvents;
use App\Orchestration\Company\ActivateCompany;
use App\Orchestration\Company\ChartOfAccountsRequirement;
use App\Orchestration\Company\FiscalCalendarRequirement;
use App\Orchestration\Ledger\CloseFiscalYear;
use App\Orchestration\Webhooks\DeliverFinancialEventWebhook;
use App\Orchestration\Webhooks\RecordFailedWebhook;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
use App\Support\Ledger\ClosingListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;

/**
 * Routes of cross-module use cases (ADR-0002). Modules never depend on this layer.
 */
class OrchestrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Prerequisites tagged `company.activation_requirements` are added by the milestones that own them.
        $this->app->tag([ChartOfAccountsRequirement::class, FiscalCalendarRequirement::class], ActivateCompany::REQUIREMENTS_TAG);

        $this->app->when(ActivateCompany::class)
            ->needs('$requirements')
            ->giveTagged(ActivateCompany::REQUIREMENTS_TAG);

        $this->app->when(CloseFiscalYear::class)->needs('$listeners')->giveTagged(ClosingListener::TAG);
    }

    public function boot(): void
    {
        // Webhooks to integration clients (API-TR-25): the outbox hands over the steps of their events after commit.
        $this->app->make(Outbox::class)->handle(FinancialEvents::CHANGED_TOPIC, fn (OutboxMessage $message) => $this->app->make(DeliverFinancialEventWebhook::class)($message));
        Event::listen(FinalWebhookCallFailedEvent::class, [RecordFailedWebhook::class, 'handle']);

        Route::prefix('api/v1')->middleware('api')->group(__DIR__.'/Http/routes.php');
    }
}
