<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionStatus;
use App\Modules\MasterData\Domain\DimensionType;
use App\Modules\MasterData\Domain\DimensionValue;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates a dimension type or value (MD-FR-02, MD-FR-03). Deactivation only blocks
 * new use: sets that already contain the value stay readable (MD-AC-02). A value cannot be
 * deactivated while it has active children, nor reactivated under an inactive parent or in an
 * inactive type.
 */
final class SetDimensionStatus
{
    public function __construct(private readonly Audit $audit) {}

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivateType(string $typeId): DimensionType
    {
        return $this->change(DimensionType::query(), $typeId, DimensionStatus::Active, DimensionStatus::Inactive, 'dimension_type.deactivated', fn (): bool => true);
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivateType(string $typeId): DimensionType
    {
        return $this->change(DimensionType::query(), $typeId, DimensionStatus::Inactive, DimensionStatus::Active, 'dimension_type.reactivated', fn (): bool => true);
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivateValue(string $valueId): DimensionValue
    {
        return $this->change(
            DimensionValue::query(), $valueId, DimensionStatus::Active, DimensionStatus::Inactive, 'dimension_value.deactivated',
            fn (DimensionValue $value): bool => ! $value->children()->where('status', DimensionStatus::Active->value)->exists(),
        );
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivateValue(string $valueId): DimensionValue
    {
        return $this->change(
            DimensionValue::query(), $valueId, DimensionStatus::Inactive, DimensionStatus::Active, 'dimension_value.reactivated',
            fn (DimensionValue $value): bool => $value->type->isActive()
                && ($value->parent_id === null || DimensionValue::query()->find($value->parent_id)?->isActive() === true),
        );
    }

    /**
     * @template TModel of DimensionType|DimensionValue
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): bool  $allowed
     * @return TModel
     */
    private function change(Builder $query, string $id, DimensionStatus $from, DimensionStatus $to, string $event, callable $allowed): DimensionType|DimensionValue
    {
        return DB::transaction(function () use ($query, $id, $from, $to, $event, $allowed) {
            $model = $query->lockForUpdate()->findOrFail($id);

            if ($model->status !== $from || ! $allowed($model)) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $model->status = $to;
            $model->save();

            $label = $model instanceof DimensionType ? $model->key : $model->code;

            $this->audit->record(
                $event,
                "Dimension {$label} changed from {$from->value} to {$to->value}.",
                $model,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $model->company_id,
            );

            return $model;
        });
    }
}
