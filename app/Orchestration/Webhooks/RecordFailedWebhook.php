<?php

namespace App\Orchestration\Webhooks;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;

/**
 * A webhook the client never accepted, after every attempt, goes to the audit trail (API-TR-25): that is where
 * the company sees it. The client can still read the step in the changes feed (TE-FR-15).
 */
final class RecordFailedWebhook
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(FinalWebhookCallFailedEvent $event): void
    {
        $companyId = $event->meta['company_id'] ?? null;

        if (! is_string($companyId)) {
            return;
        }

        $this->audit->record(
            'integration_client.webhook_failed',
            "Webhook {$event->uuid} was not accepted after {$event->attempt} attempts.",
            properties: [
                'client_id' => $event->meta['client_id'] ?? null,
                'webhook_id' => $event->uuid,
                'url' => $event->webhookUrl,
                'attempts' => $event->attempt,
                'status' => $event->response?->status(),
                'error' => $event->errorMessage,
            ],
            outcome: AuditOutcome::Failed,
            companyId: $companyId,
        );
    }
}
