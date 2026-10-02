<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionType;
use App\Modules\MasterData\Domain\DimensionValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invariants of dimension types and values (MD-FR-02, MD-FR-03) shared by the dimension actions.
 * Lookups go through the company scope, so another company's rows simply do not exist here (P-05).
 */
final class DimensionRules
{
    /**
     * @throws ValidationException
     */
    public function assertKeyAvailable(string $key): void
    {
        if (DimensionType::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => [__('dimensions.key_taken', ['key' => $key])]]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertActiveType(string $typeId): DimensionType
    {
        $type = DimensionType::query()->find($typeId)
            ?? throw ValidationException::withMessages(['dimension_type_id' => [__('dimensions.type_not_found')]]);

        if (! $type->isActive()) {
            throw ValidationException::withMessages(['dimension_type_id' => [__('dimensions.type_inactive')]]);
        }

        return $type;
    }

    /**
     * @throws ValidationException
     */
    public function assertCodeAvailable(string $typeId, string $code, ?string $exceptValueId = null): void
    {
        $taken = DimensionValue::query()
            ->where('dimension_type_id', $typeId)
            ->where('code', $code)
            ->when($exceptValueId !== null, fn ($query) => $query->whereKeyNot($exceptValueId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('dimensions.code_taken', ['code' => $code])]]);
        }
    }

    /**
     * Only a hierarchical type has parents; the parent must be an active value of the same type.
     *
     * @throws ValidationException
     */
    public function assertValidParent(DimensionType $type, string $parentId): DimensionValue
    {
        if (! $type->is_hierarchical) {
            throw ValidationException::withMessages(['parent_id' => [__('dimensions.type_not_hierarchical')]]);
        }

        $parent = DimensionValue::query()->where('dimension_type_id', $type->id)->find($parentId)
            ?? throw ValidationException::withMessages(['parent_id' => [__('dimensions.parent_not_found')]]);

        if (! $parent->isActive()) {
            throw ValidationException::withMessages(['parent_id' => [__('dimensions.parent_inactive')]]);
        }

        return $parent;
    }

    /**
     * Moving a value under itself or one of its descendants would form a cycle (MD-AC-04).
     *
     * @throws ValidationException
     */
    public function assertNoCycle(DimensionValue $value, DimensionValue $newParent): void
    {
        for ($cursor = $newParent, $guard = 0; $cursor !== null && $guard < 1000; $cursor = $cursor->parent_id === null ? null : DimensionValue::query()->find($cursor->parent_id), $guard++) {
            if ($cursor->id === $value->id) {
                throw ValidationException::withMessages(['parent_id' => [__('dimensions.parent_cycle')]]);
            }
        }
    }

    /**
     * Whether the value is part of any dimension set, i.e. was used (MD-FR-03).
     */
    public function isUsed(DimensionValue $value): bool
    {
        return DB::table('md_dimension_set_items')
            ->where('company_id', $value->company_id)
            ->where('dimension_value_id', $value->id)
            ->exists();
    }
}
