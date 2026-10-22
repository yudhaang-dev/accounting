<?php

namespace App\Orchestration\Webhooks;

use LogicException;
use Spatie\WebhookServer\WebhookCall;

/**
 * A webhook call whose signature is made by the job on each attempt (see SignedWebhookJob) instead of once
 * when it is dispatched.
 */
final class SignedWebhookCall extends WebhookCall
{
    /**
     * Queues one call. `$id` is the webhook ID the receiver deduplicates on and stays the same on every attempt.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta  travels with the call and comes back on its events
     */
    public static function send(string $url, string $secret, string $id, array $payload, array $meta): void
    {
        $call = self::create();
        $job = $call instanceof self ? $call->callWebhookJob : null;

        if (! $job instanceof SignedWebhookJob) {
            throw new LogicException('The webhook job of the configuration must be '.SignedWebhookJob::class.'.');
        }

        $job->secret = $secret;

        $call->url($url)->uuid($id)->payload($payload)->doNotSign()->useTimestamp()->meta($meta)->dispatch();
    }
}
