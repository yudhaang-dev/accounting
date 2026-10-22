<?php

namespace App\Orchestration\Webhooks;

use App\Modules\IdentityAccess\Contracts\IntegrationWebhooks;
use App\Modules\TransactionEntry\Contracts\FinancialEvents;
use App\Platform\Outbox\OutboxMessage;
use App\Support\Company\CompanyContext;

/**
 * Sends the client the step its event took (API-TR-25). The outbox row only names the step; what is sent is
 * the entry of the changes feed (TE-FR-15) as it is when the row is relayed, so a webhook never says less than
 * the feed. The row's ID is the webhook ID the receiver deduplicates on, and it stays the same however many
 * times the row is relayed or the call is retried. A client without an endpoint, or one that was revoked since,
 * is sent nothing.
 */
final class DeliverFinancialEventWebhook
{
    public const TYPE = 'financial_event.changed';

    public function __construct(
        private readonly CompanyContext $company,
        private readonly IntegrationWebhooks $webhooks,
        private readonly FinancialEvents $events,
    ) {}

    public function __invoke(OutboxMessage $message): void
    {
        $this->company->run($message->companyId, function () use ($message): void {
            $clientId = (string) $message->subjectId;
            $endpoint = $this->webhooks->endpointOf($clientId);

            if ($endpoint === null) {
                return;
            }

            $seq = (int) ($message->payload['seq'] ?? 0);
            $change = $this->events->changes((string) ($seq - 1), 1, $clientId)->changes[0] ?? null;

            // The step is the client's own and the first one after the cursor, or there is nothing to say.
            if ($change === null || $change->cursor !== (string) $seq) {
                return;
            }

            SignedWebhookCall::send($endpoint->url, $endpoint->secret, $message->id, [
                'id' => $message->id,
                'type' => self::TYPE,
                // Cursors and event IDs belong to a company; a receiver of several companies tells them apart here.
                'company_id' => $message->companyId,
                'created_at' => $change->occurredAt,
                'data' => [
                    'cursor' => $change->cursor,
                    'event_id' => $change->eventId,
                    'action' => $change->action,
                    'status' => $change->status,
                    'journal_id' => $change->journalId,
                    'journal_number' => $change->journalNumber,
                    'occurred_at' => $change->occurredAt,
                ],
            ], ['company_id' => $message->companyId, 'client_id' => $clientId]);
        });
    }
}
