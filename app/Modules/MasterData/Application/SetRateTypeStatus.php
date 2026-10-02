<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionStatus;
use App\Modules\MasterData\Domain\RateType;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates a rate type. Deactivation only stops new rates from being recorded;
 * lookups of existing rates keep working so history stays readable.
 */
final class SetRateTypeStatus
{
    public function __construct(private readonly Audit $audit) {}

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivate(string $typeId): RateType
    {
        return $this->change($typeId, DimensionStatus::Active, DimensionStatus::Inactive, 'rate_type.deactivated');
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivate(string $typeId): RateType
    {
        return $this->change($typeId, DimensionStatus::Inactive, DimensionStatus::Active, 'rate_type.reactivated');
    }

    private function change(string $typeId, DimensionStatus $from, DimensionStatus $to, string $event): RateType
    {
        return DB::transaction(function () use ($typeId, $from, $to, $event): RateType {
            $type = RateType::query()->lockForUpdate()->findOrFail($typeId);

            if ($type->status !== $from) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $type->status = $to;
            $type->save();

            $this->audit->record(
                $event,
                "Rate type {$type->key} changed from {$from->value} to {$to->value}.",
                $type,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $type->company_id,
            );

            return $type;
        });
    }
}
