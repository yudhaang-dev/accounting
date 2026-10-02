<?php

namespace App\Orchestration\Ledger\Http;

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceImport;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceLine;
use App\Modules\GeneralLedger\Contracts\OpeningBalances;
use App\Orchestration\Ledger\ImportOpeningBalances;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\ActorPermissions;
use Illuminate\Http\JsonResponse;

class OpeningBalanceImportController
{
    /**
     * The opening balance batch of the company (GL-FR-11); 404 before the import.
     */
    public function show(OpeningBalances $openingBalances, string $companyId): OpeningBalanceBatchResource
    {
        return new OpeningBalanceBatchResource($openingBalances->current($companyId) ?? throw new ApiException(ErrorCode::NotFound));
    }

    /**
     * Imports the opening balances as one `system.opening_balance` journal dated `ledger_start_date`,
     * atomically (GL-FR-11). Answers 201 with the batch, or 200 when the same `source_key` and content
     * were imported before and nothing was posted. Answers 422 `MAPPING_REQUIRED` for a source account
     * or dimension value code without a mapping, 422 for a batch that does not balance, for accounts
     * or dimensions that the posting rules refuse, or for a line on a control account of a subledger
     * that differs from its open items; 409 `IDEMPOTENCY_KEY_REUSED` for a known `source_key` with other
     * content and 409 `INVALID_STATE_TRANSITION` when the company already has opening balances.
     */
    public function store(ImportOpeningBalancesRequest $request, ImportOpeningBalances $import, ActorPermissions $permissions, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{source_key: string, source_system?: string|null, cutover_date: string, ledger_start_date?: string|null, account_mapping: list<array{source_code: string, account_code: string}>, dimension_mapping?: list<array{type: string, source_code: string, value_id: string}>, lines: list<array{source_account_code: string, debit: string|int|float, credit: string|int|float, currency?: string|null, exchange_rate?: string|int|float|null, dimensions?: array<string, string>|null, description?: string|null}>} $data */
        $data = $request->validated();

        $dimensionMapping = [];

        foreach ($data['dimension_mapping'] ?? [] as $row) {
            $dimensionMapping[$row['type']][$row['source_code']] = $row['value_id'];
        }

        $batch = $import(new OpeningBalanceImport(
            companyId: $companyId,
            sourceKey: $data['source_key'],
            cutoverDate: $data['cutover_date'],
            ledgerStartDate: $data['ledger_start_date'] ?? $data['cutover_date'],
            accountMapping: array_column($data['account_mapping'], 'account_code', 'source_code'),
            dimensionMapping: $dimensionMapping,
            lines: array_map(fn (array $line): OpeningBalanceLine => new OpeningBalanceLine(
                $line['source_account_code'],
                (string) $line['debit'],
                (string) $line['credit'],
                $line['currency'] ?? null,
                isset($line['exchange_rate']) ? (string) $line['exchange_rate'] : null,
                $line['dimensions'] ?? null,
                $line['description'] ?? null,
            ), $data['lines']),
            importedBy: $user->id,
            sourceSystem: $data['source_system'] ?? null,
            actorPermissions: $permissions->current(),
        ));

        return (new OpeningBalanceBatchResource($batch))->response()->setStatusCode($batch->replayed ? 200 : 201);
    }
}
