<?php

namespace App\Orchestration\FinancialEvents\Http;

use App\Modules\IdentityAccess\Contracts\AccessContexts;
use App\Modules\TransactionEntry\Contracts\FinancialEvent;
use App\Modules\TransactionEntry\Contracts\FinancialEvents;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FinancialEventController
{
    /**
     * Sends a financial event (TE-FR-12). It becomes an entry of the source `integration`, is validated
     * and submitted, and follows the approval policy like any entry; an approver, never the client,
     * decides. 201 with the identity and status of the event; 200 with the original result when the same content
     * comes again under its source key, 409 SOURCE_TRANSACTION_CONFLICT when other content does (TE-FR-13). 422 for a payload or a configuration that
     * does not hold, with nothing recorded; 403 for a transaction type outside the allowlist of the client.
     */
    public function store(FinancialEventRequest $request, FinancialEvents $events, AccessContexts $contexts): JsonResponse
    {
        $principal = $contexts->integrationPrincipal($request) ?? throw new ApiException(ErrorCode::Forbidden);

        /** @var array{transaction_type: array{key: string, version?: int|null}, accounting_date: string, currency?: string|null, exchange_rate?: string|null, amounts: array<string, mixed>, dimensions?: array<string, mixed>, party?: string|null, cash_account?: string|null, allocations?: array<int, mixed>|null, references: array<string, mixed>} $data */
        $data = $request->validated();

        $result = $events->receive(new FinancialEvent(
            clientId: $principal->clientId,
            sourceSystem: $principal->sourceSystem,
            transactionTypeKey: $data['transaction_type']['key'],
            transactionTypeVersion: $data['transaction_type']['version'] ?? null,
            accountingDate: $data['accounting_date'],
            currency: $data['currency'] ?? null,
            exchangeRate: $data['exchange_rate'] ?? null,
            amounts: $data['amounts'],
            dimensions: $data['dimensions'] ?? [],
            references: $data['references'],
            links: array_filter(['party' => $data['party'] ?? null, 'cash_account' => $data['cash_account'] ?? null, 'allocations' => $data['allocations'] ?? null], fn (mixed $value): bool => $value !== null),
        ));

        return (new FinancialEventResource($result))->response()->setStatusCode($result->replayed ? 200 : 201);
    }

    /**
     * The changes feed (TE-FR-15): the steps events took, oldest first, after `cursor` (from the start when
     * absent). Poll with `meta.next_cursor` of the last page; each step comes once and in order. A client
     * sees the steps of its own events, a user with `financial-events.read` those of all.
     */
    public function changes(Request $request, FinancialEvents $events, AccessContexts $contexts): AnonymousResourceCollection
    {
        $principal = $contexts->integrationPrincipal($request);

        if ($principal === null) {
            $contexts->current($request)->requirePermission('financial-events.read');
        }

        $query = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        /** @var numeric-string|null $cursor */
        $cursor = $query['cursor'] ?? null;
        $page = $events->changes($cursor, (int) ($query['limit'] ?? 100), $principal?->clientId);

        return FinancialEventChangeResource::collection($page->changes)->additional(['meta' => ['next_cursor' => $page->nextCursor]]);
    }

    /**
     * Where an event stands. A client sees only what it sent; a user needs `financial-events.read`.
     * Another client's event is 404, not 403.
     */
    public function show(Request $request, FinancialEvents $events, AccessContexts $contexts, string $companyId, string $eventId): FinancialEventResource
    {
        $principal = $contexts->integrationPrincipal($request);

        if ($principal === null) {
            $contexts->current($request)->requirePermission('financial-events.read');
        }

        $result = $events->find($eventId, $principal?->clientId) ?? throw new ApiException(ErrorCode::NotFound);

        return new FinancialEventResource($result);
    }
}
