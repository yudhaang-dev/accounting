<?php

namespace App\Platform\Outbox;

/**
 * A row of the outbox as handed to the handler of its topic.
 */
final readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $companyId,
        public string $topic,
        public ?string $subjectId,
        public array $payload,
        public int $attempts,
    ) {}
}
