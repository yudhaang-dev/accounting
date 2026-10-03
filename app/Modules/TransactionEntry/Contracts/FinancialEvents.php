<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * Intake of financial events from integration clients (TE-FR-12). Whether the client may send the
 * transaction type is for the caller to check: this module knows no clients.
 */
interface FinancialEvents
{
    /**
     * Records the event as an entry from the source `integration`, submits it and returns its result.
     * An invalid event leaves nothing behind. The key is the company, the source system and the source
     * transaction ID (TE-FR-13): the same content again returns the original result as `replayed`, however
     * many are sent at once; other content under the key fails with SOURCE_TRANSACTION_CONFLICT.
     *
     * Fails with a validation error keyed by the fields of the event (`amounts.<field>`,
     * `dimensions.<type key>`, `references.<field>`, `currency`, `accounting_date`, `transaction_type.key`),
     * or an ApiException of the ledger checks (PERIOD_NOT_OPEN, EXCHANGE_RATE_NOT_FOUND, ...).
     */
    public function receive(FinancialEvent $event): FinancialEventResult;

    /**
     * The event with that ID, or null when there is none. With a client ID, only an event that client
     * sent.
     */
    public function find(string $eventId, ?string $clientId = null): ?FinancialEventResult;

    /**
     * The steps events took after the cursor (none: from the start), oldest first, at most `$limit` (TE-FR-15).
     * Every step is given once and in the order it became visible, so polling with the last cursor misses
     * and repeats nothing. With a client ID, only the steps of events that client sent.
     *
     * @param  numeric-string|null  $cursor  as given by an earlier page
     */
    public function changes(?string $cursor, int $limit, ?string $clientId = null): FinancialEventChanges;
}
