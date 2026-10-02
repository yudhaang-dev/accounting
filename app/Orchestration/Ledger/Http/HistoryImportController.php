<?php

namespace App\Orchestration\Ledger\Http;

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\HistoryImport;
use App\Modules\GeneralLedger\Contracts\HistoryImports;
use App\Modules\GeneralLedger\Contracts\HistoryJournal;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceLine;
use App\Orchestration\Ledger\ImportHistory;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\ActorPermissions;
use Illuminate\Http\JsonResponse;

class HistoryImportController
{
    /**
     * The history import of the company (GL-FR-11); 404 before the import.
     */
    public function show(HistoryImports $history, string $companyId): HistoryImportResource
    {
        return new HistoryImportResource($history->current($companyId) ?? throw new ApiException(ErrorCode::NotFound));
    }

    /**
     * Imports the history between the ledger start and the cutover date as `system.history_import`
     * journals, atomically, and checks the ledger against the source trial balance at the cutover
     * date (GL-FR-11). Answers 201 with the import, or 200 when the same `source_key` and content were
     * imported before. Answers 409 `INVALID_STATE_TRANSITION` without an opening balance batch, when
     * the batch has no history to import or when another import exists, 409 `IDEMPOTENCY_KEY_REUSED`
     * for a known `source_key` with other content, 422 `MAPPING_REQUIRED` for a code without a
     * mapping, and 422 for dates outside the history, a period summarised twice, journals that the
     * posting rules refuse, or a ledger that differs from the source trial balance.
     */
    public function store(ImportHistoryRequest $request, ImportHistory $import, ActorPermissions $permissions, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{source_key: string, granularity: string, account_mapping?: list<array{source_code: string, account_code: string}>, dimension_mapping?: list<array{type: string, source_code: string, value_id: string}>, journals: list<array{accounting_date: string, description?: string|null, lines: list<array<string, mixed>>}>, cutover_balances: list<array<string, mixed>>} $data */
        $data = $request->validated();

        $dimensionMapping = [];

        foreach ($data['dimension_mapping'] ?? [] as $row) {
            $dimensionMapping[$row['type']][$row['source_code']] = $row['value_id'];
        }

        $line = fn (array $line): OpeningBalanceLine => new OpeningBalanceLine(
            $line['source_account_code'],
            (string) $line['debit'],
            (string) $line['credit'],
            $line['currency'] ?? null,
            isset($line['exchange_rate']) ? (string) $line['exchange_rate'] : null,
            $line['dimensions'] ?? null,
            $line['description'] ?? null,
        );

        $result = $import(new HistoryImport(
            companyId: $companyId,
            sourceKey: $data['source_key'],
            granularity: $data['granularity'],
            journals: array_map(fn (array $journal): HistoryJournal => new HistoryJournal(
                $journal['accounting_date'],
                array_map($line, $journal['lines']),
                $journal['description'] ?? null,
            ), $data['journals']),
            cutoverBalances: array_map($line, $data['cutover_balances']),
            importedBy: $user->id,
            accountMapping: array_column($data['account_mapping'] ?? [], 'account_code', 'source_code'),
            dimensionMapping: $dimensionMapping,
            actorPermissions: $permissions->current(),
        ));

        return (new HistoryImportResource($result))->response()->setStatusCode($result->replayed ? 200 : 201);
    }
}
