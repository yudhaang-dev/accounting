<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionValue;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a value to a dimension type of the current company (MD-FR-03).
 */
final class CreateDimensionValue
{
    public function __construct(
        private readonly DimensionRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{dimension_type_id: string, code: string, name: string, parent_id?: string|null, valid_from?: string|null, valid_to?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): DimensionValue
    {
        return DB::transaction(function () use ($data): DimensionValue {
            $type = $this->rules->assertActiveType($data['dimension_type_id']);
            $this->rules->assertCodeAvailable($type->id, $data['code']);

            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null) {
                $this->rules->assertValidParent($type, $parentId);
            }

            $value = DimensionValue::create([
                'dimension_type_id' => $type->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'parent_id' => $parentId,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_to' => $data['valid_to'] ?? null,
            ])->refresh();

            $this->audit->record(
                'dimension_value.created',
                "Dimension value {$type->key}/{$value->code} created.",
                $value,
                after: $value->only(['dimension_type_id', 'code', 'name', 'parent_id', 'valid_from', 'valid_to', 'status']),
                companyId: $value->company_id,
            );

            return $value;
        });
    }
}
