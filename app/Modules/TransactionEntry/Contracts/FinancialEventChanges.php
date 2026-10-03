<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * A page of the changes feed. Asking again with `nextCursor` gives what came after the page; on an
 * empty page it is the cursor that was asked with, so polling can go on from it.
 */
final readonly class FinancialEventChanges
{
    /**
     * @param  list<FinancialEventChange>  $changes
     */
    public function __construct(
        public array $changes,
        public ?string $nextCursor,
    ) {}
}
