<?php

namespace App\Modules\IdentityAccess\Contracts;

/**
 * Where and how to send the webhooks of an integration client (API-TR-25).
 */
final readonly class WebhookEndpoint
{
    public function __construct(
        public string $url,
        public string $secret,
    ) {}
}
