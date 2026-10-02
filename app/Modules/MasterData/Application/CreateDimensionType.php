<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionType;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a dimension type to the current company (MD-FR-02). No schema change is involved (MD-AC-03).
 */
final class CreateDimensionType
{
    public function __construct(
        private readonly DimensionRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{key: string, label: string, is_hierarchical?: bool, leaf_only?: bool}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): DimensionType
    {
        return DB::transaction(function () use ($data): DimensionType {
            $this->rules->assertKeyAvailable($data['key']);

            $type = DimensionType::create([
                'key' => $data['key'],
                'label' => $data['label'],
                'is_hierarchical' => $data['is_hierarchical'] ?? false,
                'leaf_only' => $data['leaf_only'] ?? false,
            ])->refresh();

            $this->audit->record(
                'dimension_type.created',
                "Dimension type {$type->key} created.",
                $type,
                after: $type->only(['key', 'label', 'is_hierarchical', 'leaf_only', 'status']),
                companyId: $type->company_id,
            );

            return $type;
        });
    }
}
