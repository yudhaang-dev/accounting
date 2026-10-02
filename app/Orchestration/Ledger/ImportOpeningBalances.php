<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\OpeningBalanceBatchData;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceImport;
use App\Modules\GeneralLedger\Contracts\OpeningBalances;
use App\Platform\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports the opening balances in one database transaction (DOMAIN-DEPENDENCIES, "Saldo konversi").
 * The ledger posts the batch and refuses it when a control account differs from the totals this use
 * case hands it. Until the subledger can record opening open items (SL-FR-10, M6-10) there are no such
 * totals, so a batch with a line on a control account is refused.
 */
final class ImportOpeningBalances
{
    public function __construct(private readonly OpeningBalances $openingBalances) {}

    /**
     * @throws ApiException
     * @throws ValidationException
     */
    public function __invoke(OpeningBalanceImport $command): OpeningBalanceBatchData
    {
        return DB::transaction(fn (): OpeningBalanceBatchData => $this->openingBalances->import($command));
    }
}
