<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * A financial event as the entry module takes it (TE-FR-12, API-TR-17): values the source system has
 * already computed (P-02). `clientId` and `sourceSystem` come from the principal, never from a body.
 */
final readonly class FinancialEvent
{
    /**
     * @param  array<string, mixed>  $amounts  field name => decimal string
     * @param  array<string, mixed>  $dimensions  dimension type key => value ID, or null
     * @param  array<string, mixed>  $references  `source_transaction_id`, `source_document_number` and other metadata, kept as sent
     * @param  array<string, mixed>  $links  `party`, `cash_account` and `allocations`, for the fields of those types
     */
    public function __construct(
        public string $clientId,
        public string $sourceSystem,
        public string $transactionTypeKey,
        public ?int $transactionTypeVersion,
        public string $accountingDate,
        public ?string $currency,
        public ?string $exchangeRate,
        public array $amounts,
        public array $dimensions,
        public array $references,
        public array $links = [],
    ) {}
}
