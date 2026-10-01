<?php

return [

    /*
    | Published API versions (API-TR-01). Each is served under /api/<name> and documented at
    | /docs/<name> (OpenAPI at /docs/<name>.json). `release` is the semantic version shown in the
    | docs; bump it for compatible changes, and add a new entry (with its own routes) for a
    | breaking one. `current_version` is where /docs/api redirects.
    */
    'versions' => [
        'v1' => ['release' => env('API_V1_RELEASE', '1.0.0')],
    ],

    'current_version' => 'v1',

    'rate_limit' => [
        // Requests per minute per principal (API-TR-15). Integration clients get their own, usually higher, budget.
        'user_per_minute' => (int) env('API_RATE_LIMIT_USER', 120),
        'client_per_minute' => (int) env('API_RATE_LIMIT_CLIENT', 600),
        // Unauthenticated callers are limited per IP.
        'guest_per_minute' => (int) env('API_RATE_LIMIT_GUEST', 30),
    ],

    'idempotency' => [
        // How long an Idempotency-Key is remembered (API-TR-13).
        'ttl_hours' => (int) env('API_IDEMPOTENCY_TTL_HOURS', 24),
    ],

];
