<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * One rule of the approval policy (TE-FR-09). An entry takes the first rule, in order, whose conditions
 * it meets; a condition that is null matches everything.
 */
final readonly class ApprovalRule
{
    /**
     * @param  string|null  $transactionTypeKey  key of the transaction type the rule is for
     * @param  string|null  $source  `manual`, `integration` or `recurring`
     * @param  string|null  $threshold  decimal string; the rule applies to entries whose total debit in the functional currency is above it
     * @param  list<string>  $stages  permission each approval stage requires, in order; empty approves the entry automatically
     * @param  bool  $autoPost  whether the entry is posted as soon as the last stage approved it
     */
    public function __construct(
        public ?string $transactionTypeKey = null,
        public ?string $source = null,
        public ?string $threshold = null,
        public array $stages = [],
        public bool $autoPost = false,
    ) {}
}
