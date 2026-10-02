<?php

namespace App\Orchestration\Ledger;

use App\Modules\GeneralLedger\Contracts\HistoryImport;
use App\Modules\GeneralLedger\Contracts\HistoryImportData;
use App\Modules\GeneralLedger\Contracts\HistoryImports;
use App\Platform\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports the history in one database transaction, like the opening balances (ImportOpeningBalances).
 * The subledger has no totals for control accounts yet (M6-10), so history on a control account is refused.
 */
final class ImportHistory
{
    public function __construct(private readonly HistoryImports $history) {}

    /**
     * @throws ApiException
     * @throws ValidationException
     */
    public function __invoke(HistoryImport $command): HistoryImportData
    {
        return DB::transaction(fn (): HistoryImportData => $this->history->import($command));
    }
}
