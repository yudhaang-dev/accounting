<?php

return [

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
