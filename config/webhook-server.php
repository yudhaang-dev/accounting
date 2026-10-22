<?php

use App\Orchestration\Webhooks\SignedWebhookJob;
use Spatie\WebhookServer\BackoffStrategy\ExponentialBackoffStrategy;
use Spatie\WebhookServer\Signer\DefaultSigner;

// Webhooks to integration clients (API-TR-25). Every call is signed by SignedWebhookJob on each attempt,
// so the package's own signer is not used.
return [

    'queue' => env('WEBHOOK_QUEUE', 'default'),

    'connection' => null,

    'http_verb' => 'post',

    'proxy' => null,

    'signer' => DefaultSigner::class,

    'signature_header_name' => 'Webhook-Signature',

    'timestamp_header_name' => 'Webhook-Timestamp',

    // The receiver processes a webhook once per this ID (API-TR-25).
    'webhook_id_header_name' => 'Webhook-Id',

    'headers' => [
        'Content-Type' => 'application/json',
        'User-Agent' => 'Accounting-Webhook/1',
    ],

    'timeout_in_seconds' => (int) env('WEBHOOK_TIMEOUT_SECONDS', 10),

    // Attempts per webhook, the first included. ExponentialBackoffStrategy waits 10 s, 100 s, 1000 s, 10 000 s and then
    // 100 000 s (about 28 hours) between them, so the default of 8 gives up after about three and a half days.
    'tries' => (int) env('WEBHOOK_TRIES', 8),

    'backoff_strategy' => ExponentialBackoffStrategy::class,

    'retryable_status_codes' => null,

    'respect_retry_after' => true,

    'retry_with_jitter' => false,

    'webhook_job' => SignedWebhookJob::class,

    'verify_ssl' => true,

    'throw_exception_on_failure' => false,

    'tags' => [],
];
