<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * Command to import the opening balances of a company in one batch (GL-FR-11).
 */
final readonly class OpeningBalanceImport
{
    /**
     * @param  string  $sourceKey  identifies the batch of the source: importing it again with the same content changes nothing
     * @param  string  $cutoverDate  `YYYY-MM-DD`, first day the company is booked in the system
     * @param  string  $ledgerStartDate  `YYYY-MM-DD`, start of the migrated history and the date of the balances; the first day of a period, equal to `$cutoverDate` without history
     * @param  array<string, string>  $accountMapping  source account code => account code of the company
     * @param  array<string, array<string, string>>  $dimensionMapping  dimension type key => source value code => dimension value ID
     * @param  list<OpeningBalanceLine>  $lines
     * @param  array<string, string>|null  $controlTotals  net debit minus credit per control account ID that the subledger holds in open items; control accounts of the batch must match it, and the batch is refused for a control account that is not in it
     * @param  list<string>  $actorPermissions  permissions the application layer found the actor to hold
     * @param  string|null  $controlDetailDigest  digest of what stands behind `$controlTotals`, so that importing the batch again with other detail under the same totals is refused
     */
    public function __construct(
        public string $companyId,
        public string $sourceKey,
        public string $cutoverDate,
        public string $ledgerStartDate,
        public array $accountMapping,
        public array $dimensionMapping,
        public array $lines,
        public int $importedBy,
        public ?string $sourceSystem = null,
        public ?array $controlTotals = null,
        public array $actorPermissions = [],
        public ?string $controlDetailDigest = null,
    ) {}
}
