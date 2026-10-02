<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Command to import the history between the ledger start date and the cutover date (GL-FR-11).
 */
final readonly class HistoryImport
{
    public const PERIOD_SUMMARY = 'period_summary';

    public const TRANSACTIONS = 'transactions';

    /**
     * @param  string  $sourceKey  identifies the import of the source: importing it again with the same content changes nothing
     * @param  string  $granularity  `period_summary` (one journal per period) or `transactions`
     * @param  list<HistoryJournal>  $journals
     * @param  list<OpeningBalanceLine>  $cutoverBalances  trial balance of the source at the cutover date, one line per account, in the functional currency; the ledger must carry the same net balance per account
     * @param  array<string, string>  $accountMapping  mappings added to those of the opening balance batch; these win
     * @param  array<string, array<string, string>>  $dimensionMapping  same, for dimension values
     * @param  array<string, string>|null  $controlTotals  net debit minus credit per control account ID that the subledger holds in open items at the cutover date
     * @param  list<string>  $actorPermissions  permissions the application layer found the actor to hold
     */
    public function __construct(
        public string $companyId,
        public string $sourceKey,
        public string $granularity,
        public array $journals,
        public array $cutoverBalances,
        public int $importedBy,
        public array $accountMapping = [],
        public array $dimensionMapping = [],
        public ?array $controlTotals = null,
        public array $actorPermissions = [],
    ) {}
}
