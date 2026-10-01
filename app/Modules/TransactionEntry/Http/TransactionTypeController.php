<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Application\CreateTransactionType;
use App\Modules\TransactionEntry\Domain\TransactionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
     * Defines a transaction type with a published first version in `free_lines` mode.
     */
    public function store(CreateTransactionTypeRequest $request, CreateTransactionType $create): JsonResponse
    {
        /** @var array{key: string, label: string} $data */
        $data = $request->validated();

        return (new TransactionTypeResource($create($data['key'], $data['label'])))->response()->setStatusCode(201);
    }
}
