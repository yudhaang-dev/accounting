<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionType;
use App\Modules\MasterData\Domain\DimensionValue;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes label, hierarchy flag or the leaf-only rule of a type. The key never changes (MD-FR-02).
 * A type cannot stop being hierarchical while some of its values have a parent.
 */
final class UpdateDimensionType
{
    private const FIELDS = ['label', 'is_hierarchical', 'leaf_only'];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data  any of label, is_hierarchical, leaf_only
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $typeId, array $data, int $expectedVersion): DimensionType
    {
        return DB::transaction(function () use ($typeId, $data, $expectedVersion): DimensionType {
            $type = DimensionType::query()->lockForUpdate()->findOrFail($typeId);
            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            if (
                array_key_exists('is_hierarchical', $changes)
                && ! $changes['is_hierarchical']
                && $type->is_hierarchical
                && DimensionValue::query()->where('dimension_type_id', $type->id)->whereNotNull('parent_id')->exists()
            ) {
                throw ValidationException::withMessages(['is_hierarchical' => [__('dimensions.hierarchy_in_use')]]);
            }

            $before = $type->only(array_keys($changes));
            $type->updateLocked($changes, $expectedVersion);
            $after = $type->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record(
                    'dimension_type.updated',
                    "Dimension type {$type->key} updated.",
                    $type,
                    before: $before,
                    after: $after,
                    companyId: $type->company_id,
                );
            }

            return $type->refresh();
        });
    }
}
