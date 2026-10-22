<?php

namespace App\Modules\Consolidation\Http;

use App\Models\User;
use App\Modules\Consolidation\Application\ManageTranslationPolicy;
use Illuminate\Http\JsonResponse;

class TranslationPolicyController
{
    /**
     * The latest version of the translation policy of the group; `version` 0 with no rate types when it has none.
     *
     * @response array{data: array{version: int, effective_date: string|null, closing_rate_type_id: string|null, average_rate_type_id: string|null, historical_rate_type_id: string|null, difference_account_id: string|null, created_by: int|null}}
     */
    public function show(ManageTranslationPolicy $policy, string $group): TranslationPolicyResource|JsonResponse
    {
        $current = $policy->current($group);

        return $current === null
            ? response()->json(['data' => ['version' => 0, 'effective_date' => null, 'closing_rate_type_id' => null, 'average_rate_type_id' => null, 'historical_rate_type_id' => null, 'difference_account_id' => null, 'created_by' => null]])
            : new TranslationPolicyResource($current);
    }

    /**
     * Saves the policy as a new version (P-04). `lock_version` is the version read; another saver in between
     * is VERSION_CONFLICT.
     */
    public function update(SaveTranslationPolicyRequest $request, ManageTranslationPolicy $policy, string $group): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{closing_rate_type_id: string, average_rate_type_id: string, historical_rate_type_id: string, difference_account_id: string} $rules */
        $rules = $request->safe()->only(['closing_rate_type_id', 'average_rate_type_id', 'historical_rate_type_id', 'difference_account_id']);

        return (new TranslationPolicyResource($policy->replace(
            $group,
            $rules,
            $request->string('effective_date')->toString() ?: now()->toDateString(),
            $request->integer('lock_version'),
            $user->id,
        )))->response()->setStatusCode(200);
    }
}
