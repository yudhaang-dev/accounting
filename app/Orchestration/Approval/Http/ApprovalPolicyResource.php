<?php

namespace App\Orchestration\Approval\Http;

use App\Modules\TransactionEntry\Contracts\ApprovalPolicyData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ApprovalPolicyData $resource
 */
class ApprovalPolicyResource extends JsonResource
{
    /**
     * @return array{version: int, separation_of_duties: bool, rules: list<array{transaction_type_key: string|null, source: string|null, threshold: string|null, stages: list<string>, auto_post: bool}>, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        $rules = [];

        foreach ($this->resource->rules as $rule) {
            $rules[] = [
                'transaction_type_key' => $rule->transactionTypeKey,
                'source' => $rule->source,
                'threshold' => $rule->threshold,
                'stages' => $rule->stages,
                'auto_post' => $rule->autoPost,
            ];
        }

        return [
            // 0 while the company has never set a policy.
            'version' => $this->resource->version,
            'separation_of_duties' => $this->resource->separationOfDuties,
            'rules' => $rules,
            // Same as `version`, named as the concurrency field the update expects (API-TR-14).
            'lock_version' => $this->resource->version,
        ];
    }
}
