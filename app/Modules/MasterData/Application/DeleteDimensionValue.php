<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionValue;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a value that was never used. A value that has children or belongs to any dimension set can
 * only be deactivated (MD-FR-03, MD-AC-04).
 */
final class DeleteDimensionValue
{
    public function __construct(
        private readonly DimensionRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function __invoke(string $valueId): void
    {
        DB::transaction(function () use ($valueId): void {
            $value = DimensionValue::query()->lockForUpdate()->findOrFail($valueId);

            if ($value->children()->exists() || $this->rules->isUsed($value)) {
                throw new ApiException(ErrorCode::InvalidStateTransition, __('dimensions.value_in_use'));
            }

            $before = $value->only(['dimension_type_id', 'code', 'name', 'parent_id', 'valid_from', 'valid_to', 'status']);
            $value->delete();

            $this->audit->record(
                'dimension_value.deleted',
                "Dimension value {$value->code} deleted.",
                $value,
                before: $before,
                companyId: $value->company_id,
            );
        });
    }
}
