<?php

namespace App\Platform\Api;

use RuntimeException;

/**
 * Domain error rendered as `{ message, code, errors? }` with the status of its code (API-TR-12).
 * Never carries `data`.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages, dot notation for nested fields
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message ?? $errorCode->message());
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }
}
