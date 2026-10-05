<?php

namespace App\Modules\Subledger\Contracts;

/**
 * One open item of the source system at the conversion date (SL-FR-10): what is left to settle of a document.
 */
final readonly class OpeningOpenItem
{
    /**
     * @param  string|null  $partyId  the party of the company; or give `$partyRef`
     * @param  string|null  $partyRef  how the source system refers to the party (SL-FR-01), under the source system of the batch
     * @param  DocumentKind  $kind  `Invoice` for what raises the balance of the control account, `CreditNote` for what lowers it, such as a credit note or an advance not applied yet
     * @param  string  $documentDate  `YYYY-MM-DD`
     * @param  string|null  $dueDate  `YYYY-MM-DD`; the document date when null
     * @param  string|null  $currency  the functional currency when null
     * @param  string  $amount  what is left to settle, in `$currency`
     * @param  string|null  $exchangeRate  functional currency per 1 unit of `$currency`; required for a foreign currency
     */
    public function __construct(
        public ControlRole $role,
        public DocumentKind $kind,
        public string $documentNumber,
        public string $documentDate,
        public string $amount,
        public ?string $partyId = null,
        public ?string $partyRef = null,
        public ?string $dueDate = null,
        public ?string $currency = null,
        public ?string $exchangeRate = null,
    ) {}
}
