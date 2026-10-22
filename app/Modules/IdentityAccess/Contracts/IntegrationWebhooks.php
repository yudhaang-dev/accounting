<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * The webhook endpoints of integration clients (API-TR-25), for whoever delivers to them. Works for the
 * current company.
 */
interface IntegrationWebhooks
{
    /**
     * The endpoint of an active client that has a URL and a secret; null when it has none to send to,
     * the client is revoked, or it is not a client of the current company.
     */
    public function endpointOf(string $clientId): ?WebhookEndpoint;
}
