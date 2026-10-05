<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\OpeningBalanceBatchData;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceImport;
use App\Modules\GeneralLedger\Contracts\OpeningBalances;
use App\Modules\Subledger\Contracts\OpeningOpenItem;
use App\Modules\Subledger\Contracts\OpeningOpenItemBatch;
use App\Modules\Subledger\Contracts\OpeningOpenItems;
use App\Platform\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports the opening balances in one database transaction (DOMAIN-DEPENDENCIES, "Saldo konversi"). The open
 * items of the conversion date (SL-FR-10) are checked first and their total per control account is handed to
 * the ledger, which posts the batch and refuses it when a control account differs from its total (GL-AC-07,
 * SL-AC-01); only then are the open items recorded, with the journal. A batch without open items has no totals,
 * so a line on a control account refuses it. Importing a batch again records nothing more.
 */
final class ImportOpeningBalances
{
    public function __construct(
        private readonly OpeningBalances $openingBalances,
        private readonly OpeningOpenItems $openItems,
    ) {}

    /**
     * @param  list<OpeningOpenItem>  $items
     *
     * @throws ApiException
     * @throws ValidationException
     */
    public function __invoke(OpeningBalanceImport $command, array $items = []): OpeningBalanceBatchData
    {
        return DB::transaction(function () use ($command, $items): OpeningBalanceBatchData {
            if ($items === []) {
                return $this->openingBalances->import($command);
            }

            $batch = new OpeningOpenItemBatch($command->companyId, $command->ledgerStartDate, $items, $command->importedBy, $command->sourceSystem);

            $posted = $this->openingBalances->import(new OpeningBalanceImport(
                companyId: $command->companyId,
                sourceKey: $command->sourceKey,
                cutoverDate: $command->cutoverDate,
                ledgerStartDate: $command->ledgerStartDate,
                accountMapping: $command->accountMapping,
                dimensionMapping: $command->dimensionMapping,
                lines: $command->lines,
                importedBy: $command->importedBy,
                sourceSystem: $command->sourceSystem,
                controlTotals: $this->openItems->totals($batch),
                actorPermissions: $command->actorPermissions,
                controlDetailDigest: hash('sha256', json_encode(array_map(fn (OpeningOpenItem $item): array => (array) $item, $items), JSON_THROW_ON_ERROR)),
            ));

            if (! $posted->replayed) {
                $this->openItems->record($batch, $posted->journalId);
            }

            return $posted;
        });
    }
}
