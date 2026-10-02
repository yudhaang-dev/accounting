<?php

namespace App\Orchestration\Ledger\Http;

use App\Modules\GeneralLedger\Contracts\LedgerPolicyData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read LedgerPolicyData $resource
 */
class LedgerPolicyResource extends JsonResource
{
    /**
     * @return array{version: int, soft_closed_transaction_type_keys: list<string>, soft_closed_permission: string|null, source_override_permission: string|null, lock_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            // 0 while the company has never set a policy.
            'version' => $this->resource->version,
            'soft_closed_transaction_type_keys' => $this->resource->softClosedTransactionTypeKeys,
            'soft_closed_permission' => $this->resource->softClosedPermission,
            'source_override_permission' => $this->resource->sourceOverridePermission,
            // Same as `version`, named as the concurrency field the update expects (API-TR-14).
            'lock_version' => $this->resource->version,
        ];
    }
}
