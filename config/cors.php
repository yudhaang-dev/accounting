<?php

return [

    /*
    | CORS is limited to the first-party SPA origins (API-TR-02). It is not authorization: it only tells
    | browsers which origins may read API responses. Machine clients are not browsers and are unaffected.
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Accept-Language', 'Content-Type', 'Idempotency-Key', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => ['Retry-After', 'Content-Language'],

    'max_age' => 600,

    'supports_credentials' => true,

];
