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
     * Says when the type is used, for the catalog an operator picks from. An empty description clears it.
     */
    public function describe(DescribeTransactionTypeRequest $request, string $companyId, string $id, Audit $audit): TransactionTypeResource
    {
        $description = $request->validated('description');
        $description = is_string($description) && trim($description) !== '' ? trim($description) : null;

        return DB::transaction(function () use ($description, $id, $audit, $companyId): TransactionTypeResource {
            $type = TransactionType::query()->lockForUpdate()->findOrFail($id);
            $before = $type->description;

            $type->description = $description;
            $type->save();

            $audit->record('transaction_type.description_changed', "Description of transaction type {$type->key} changed.", $type, before: ['description' => $before], after: ['description' => $description], companyId: $companyId);

            return new TransactionTypeResource($type->load('versions'));
        });
    }

    /**
     * Defines a transaction type with a published first version in `free_lines` mode.
     */
    public function store(CreateTransactionTypeRequest $request, CreateTransactionType $create): JsonResponse
    {
        /** @var array{key: string, label: string, description?: string|null} $data */
        $data = $request->validated();

        return (new TransactionTypeResource($create($data['key'], $data['label'], $data['description'] ?? null)))->response()->setStatusCode(201);
    }
}
