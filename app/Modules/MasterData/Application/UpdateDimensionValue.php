<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionValue;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes code, name, parent or validity of a value. The type never changes. Hierarchy changes are
 * audited with their before/after parent (MD-FR-05); history is unaffected because relations use IDs.
 */
final class UpdateDimensionValue
{
    private const FIELDS = ['code', 'name', 'parent_id', 'valid_from', 'valid_to'];

    public function __construct(
        private readonly DimensionRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  any of code, name, parent_id, valid_from, valid_to
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $valueId, array $data, int $expectedVersion): DimensionValue
    {
        return DB::transaction(function () use ($valueId, $data, $expectedVersion): DimensionValue {
            $value = DimensionValue::query()->lockForUpdate()->findOrFail($valueId);
            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($changes['code']) && $changes['code'] !== $value->code) {
                $this->rules->assertCodeAvailable($value->dimension_type_id, $changes['code'], $value->id);
            }

            if (array_key_exists('parent_id', $changes) && $changes['parent_id'] !== $value->parent_id && $changes['parent_id'] !== null) {
                $parent = $this->rules->assertValidParent($value->type, $changes['parent_id']);
                $this->rules->assertNoCycle($value, $parent);
            }

            $this->assertValidity($value, $changes);

            $before = $value->only(array_keys($changes));
            $value->updateLocked($changes, $expectedVersion);
            $after = $value->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record(
                    'dimension_value.updated',
                    "Dimension value {$value->code} updated.",
                    $value,
                    before: $before,
                    after: $after,
                    companyId: $value->company_id,
                );
            }

            return $value->refresh();
        });
    }

    /**
     * The request checks the end against the start when both are sent; this covers a partial change.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws ValidationException
     */
    private function assertValidity(DimensionValue $value, array $changes): void
    {
        $from = array_key_exists('valid_from', $changes) ? $changes['valid_from'] : $value->valid_from?->toDateString();
        $to = array_key_exists('valid_to', $changes) ? $changes['valid_to'] : $value->valid_to?->toDateString();

        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['valid_to' => [__('dimensions.validity_order')]]);
        }
    }
}
