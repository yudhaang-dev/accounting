<?php

// Generic API error messages (API-TR-06). Business error codes are added with the error catalog (M0-10).
return [

    'unauthenticated' => 'Authentication is required.',
    'forbidden' => 'You are not allowed to perform this action.',
    'not_found' => 'The requested resource was not found.',
    'validation_failed' => 'The given data was invalid.',
    'server_error' => 'An unexpected error occurred.',

    'codes' => [
        'SOURCE_TRANSACTION_CONFLICT' => 'A transaction with this source key already exists with different content.',
        'IDEMPOTENCY_KEY_REUSED' => 'This Idempotency-Key was already used with a different request body.',
        'VERSION_CONFLICT' => 'The resource was changed by someone else. Reload and try again.',
        'INVALID_STATE_TRANSITION' => 'This action is not allowed in the current status.',
        'PERIOD_NOT_OPEN' => 'The period does not accept postings.',
        'ACCOUNT_NOT_POSTABLE' => 'The account cannot receive postings.',
        'POSTING_SOURCE_NOT_ALLOWED' => 'The account does not accept this posting source.',
        'DIMENSION_REQUIRED' => 'A required dimension is missing.',
        'DIMENSION_INVALID' => 'A dimension value is invalid.',
        'UNBALANCED_ENTRY' => 'The entry is not balanced in the functional currency.',
        'EXCHANGE_RATE_NOT_FOUND' => 'No exchange rate is available.',
        'TRANSACTION_TYPE_VERSION_RETIRED' => 'This transaction type version is retired.',
        'MAPPING_REQUIRED' => 'A required mapping or policy is not configured.',
        'TRANSACTION_TYPE_NOT_ALLOWED' => 'This client may not use this transaction type.',
        'SEGREGATION_OF_DUTIES' => 'You cannot approve your own entry.',
        'TWO_FACTOR_REQUIRED' => 'This action requires two-factor authentication.',
        'UNAUTHENTICATED' => 'Authentication is required.',
        'FORBIDDEN' => 'You are not allowed to perform this action.',
        'NOT_FOUND' => 'The requested resource was not found.',
        'CSRF_TOKEN_MISMATCH' => 'The CSRF token is missing or invalid.',
        'TOO_MANY_REQUESTS' => 'Too many requests. Please slow down.',
        'SERVER_ERROR' => 'An unexpected error occurred.',
    ],

];
