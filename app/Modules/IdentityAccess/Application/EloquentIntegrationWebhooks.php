<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Contracts\IntegrationWebhooks;
use App\Modules\IdentityAccess\Contracts\WebhookEndpoint;
use App\Modules\IdentityAccess\Domain\IntegrationClient;
use App\Modules\IdentityAccess\Domain\IntegrationClientStatus;

final class EloquentIntegrationWebhooks implements IntegrationWebhooks
{
    public function endpointOf(string $clientId): ?WebhookEndpoint
    {
        $client = IntegrationClient::query()->where('status', IntegrationClientStatus::Active->value)->find($clientId);

        if ($client === null || $client->webhook_url === null || $client->webhook_secret === null) {
            return null;
        }

        return new WebhookEndpoint($client->webhook_url, $client->webhook_secret);
    }
}
