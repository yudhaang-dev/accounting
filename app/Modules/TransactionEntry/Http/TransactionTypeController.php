<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Application\CreateTransactionType;
use App\Modules\TransactionEntry\Application\EntryNumbers;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Platform\Audit\Audit;
use App\Platform\Numbering\ResetPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TransactionTypeController
{
    /** Transaction types of the company with their versions, ordered by key. */
    public function index(): AnonymousResourceCollection
    {
        return TransactionTypeResource::collection(TransactionType::query()->with('versions')->orderBy('key')->get());
    }

    public function show(string $companyId, string $id): TransactionTypeResource
    {
        return new TransactionTypeResource(TransactionType::query()->with('versions')->findOrFail($id));
    }

    /**
     * Sets the number series of a type and when its entries are numbered (TE-FR-16).
     */
    public function numbering(NumberingRequest $request, string $companyId, string $id, EntryNumbers $numbers, Audit $audit): TransactionTypeResource
    {
        /** @var array{pattern: string, padding: int, reset: string, policy: string} $data */
        $data = $request->validated();

        return DB::transaction(function () use ($data, $id, $numbers, $audit, $companyId): TransactionTypeResource {
            $type = TransactionType::query()->lockForUpdate()->findOrFail($id);
            $before = ['numbering' => $numbers->describe($type->key), 'number_policy' => $type->number_policy];

            $numbers->configure($type, $data['pattern'], $data['padding'], ResetPeriod::from($data['reset']), $data['policy']);

            $audit->record('transaction_type.numbering_changed', "Numbering of transaction type {$type->key} changed.", $type, before: $before, after: ['numbering' => $numbers->describe($type->key), 'number_policy' => $type->number_policy], companyId: $companyId);

            return new TransactionTypeResource($type->load('versions'));
        });
    }

    /**
     * Defines a transaction type with a published first version in `free_lines` mode.
     */
    public function store(CreateTransactionTypeRequest $request, CreateTransactionType $create): JsonResponse
    {
        /** @var array{key: string, label: string} $data */
        $data = $request->validated();

        return (new TransactionTypeResource($create($data['key'], $data['label'])))->response()->setStatusCode(201);
    }
}
