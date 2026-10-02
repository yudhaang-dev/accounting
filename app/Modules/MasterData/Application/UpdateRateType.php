<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\RateType;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Changes the label or the age limit of a rate type. The key never changes (MD-FR-07).
 */
final class UpdateRateType
{
    private const FIELDS = ['label', 'max_age_days'];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data  any of label, max_age_days
     *
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $typeId, array $data, int $expectedVersion): RateType
    {
        return DB::transaction(function () use ($typeId, $data, $expectedVersion): RateType {
            $type = RateType::query()->lockForUpdate()->findOrFail($typeId);
            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            $before = $type->only(array_keys($changes));
            $type->updateLocked($changes, $expectedVersion);
            $after = $type->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record(
                    'rate_type.updated',
                    "Rate type {$type->key} updated.",
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
