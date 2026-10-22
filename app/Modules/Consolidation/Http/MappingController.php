<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\ManageMapping;
use Illuminate\Http\JsonResponse;

class MappingController
{
    /**
     * The latest version of the account mapping of the group; `version` 0 with no rows when it has none.
     *
     * @response array{data: array{version: int, effective_date: string|null, created_by: int|null, mappings: list<array{company_id: string, account_id: string, account_code: string, group_account_id: string}>}}
     */
    public function show(ManageMapping $mapping, string $group): MappingVersionResource|JsonResponse
    {
        $current = $mapping->current($group);

        return $current === null
            ? response()->json(['data' => ['version' => 0, 'effective_date' => null, 'created_by' => null, 'mappings' => []]])
            : new MappingVersionResource($current);
    }

    /**
     * Saves the whole mapping as a new version (P-04). `lock_version` is the version read; another saver in
     * between is VERSION_CONFLICT.
     */
    public function update(SaveMappingRequest $request, ManageMapping $mapping, string $group): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<array{company_id: string, account_id: string, group_account_id: string}> $rows */
        $rows = $request->validated('mappings');

        return (new MappingVersionResource($mapping->replace(
            $group,
            $rows,
            $request->string('effective_date')->toString() ?: now()->toDateString(),
            $request->integer('lock_version'),
            $user->id,
        )->load('rows')))->response()->setStatusCode(200);
    }
}
