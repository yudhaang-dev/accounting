<?php

namespace App\Orchestration\Webhooks;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Spatie\WebhookServer\CallWebhookJob;

/**
 * One webhook call (API-TR-25), signed again on every attempt so that a retry hours later still carries a
 * timestamp the receiver will accept. The signature is `v1=` and the hex HMAC-SHA256, with the secret of the
 * client, of `{Webhook-Id}.{Webhook-Timestamp}.{body}`. The job is encrypted on the queue because it holds the secret.
 */
final class SignedWebhookJob extends CallWebhookJob implements ShouldBeEncrypted
{
    public string $secret = '';

    protected function addTimestampToHeaders(): void
    {
        $timestamp = (string) now()->timestamp;

        $this->headers[(string) config('webhook-server.timestamp_header_name')] = $timestamp;
        $this->headers[(string) config('webhook-server.signature_header_name')] = 'v1='.hash_hmac('sha256', "{$this->uuid}.{$timestamp}.{$this->getBody()}", $this->secret);
    }
}
