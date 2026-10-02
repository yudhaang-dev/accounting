<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\TransactionEntry\Application\TransactionTypeVersions;
use App\Modules\TransactionEntry\Domain\FieldSchema;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use Illuminate\Http\JsonResponse;

/**
 * Versions of a transaction type: draft, edit, publish and retire (TE-FR-02), and the field schema
 * of a version as JSON Schema (TE-FR-04).
 */
class TransactionTypeVersionController
{
    /** Starts a draft with the next version number. */
    public function store(VersionRequest $request, string $companyId, string $id, TransactionTypeVersions $versions): JsonResponse
    {
        $versions->createDraft($id, TransactionTypeMode::from($request->string('mode')->toString()), $this->fields($request));

        return $this->type($id)->response()->setStatusCode(201);
    }

    /** Replaces the content of a draft version. */
    public function update(VersionRequest $request, string $companyId, string $id, int $version, TransactionTypeVersions $versions): TransactionTypeResource
    {
        $versions->updateDraft($id, $version, TransactionTypeMode::from($request->string('mode')->toString()), $this->fields($request));

        return $this->type($id);
    }

    public function publish(string $companyId, string $id, int $version, TransactionTypeVersions $versions): TransactionTypeResource
    {
        $versions->publish($id, $version);

        return $this->type($id);
    }

    public function retire(string $companyId, string $id, int $version, TransactionTypeVersions $versions): TransactionTypeResource
    {
        $versions->retire($id, $version);

        return $this->type($id);
    }

    /**
     * The field schema of a version as a JSON Schema document; a free-lines version has no schema.
     */
    public function schema(string $companyId, string $id, int $version): JsonResponse
    {
        TransactionType::query()->findOrFail($id);

        $found = TransactionTypeVersion::query()->where('transaction_type_id', $id)->where('version', $version)->firstOrFail();

        return response()->json(['data' => $found->fields === null ? null : FieldSchema::toJsonSchema($found->fields)]);
    }

    private function type(string $id): TransactionTypeResource
    {
        return new TransactionTypeResource(TransactionType::query()->with('versions')->findOrFail($id));
    }

    /**
     * @return list<array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, dimension_type?: string}>
     */
    private function fields(VersionRequest $request): array
    {
        /** @var list<array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, dimension_type?: string}> */
        return $request->validated('fields', []);
    }
}
