<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\RateType;
use App\Modules\Consolidation\Domain\TranslationPolicy;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rate types of a group (CO-FR-06). A type is never deleted (X-FR-05); one no longer wanted is deactivated,
 * unless the current translation policy still names it.
 */
final class ManageRateTypes
{
    public function __construct(private readonly Audit $audit) {}

    /** @throws ValidationException */
    public function create(string $groupId, string $code, string $name): RateType
    {
        try {
            return DB::transaction(function () use ($groupId, $code, $name): RateType {
                $group = Group::query()->lockForUpdate()->findOrFail($groupId);
                $type = RateType::create(['group_id' => $group->id, 'code' => $code, 'name' => $name])->refresh();

                $this->audit->record('group_rate_type.created', "Group rate type {$code} created.", $type, after: $this->snapshot($type), properties: ['group_id' => $group->id], companyId: $group->parent_company_id);

                return $type;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => [__('consolidation.rate_type_code_taken')]]);
        }
    }

    /**
     * @param  array<string, mixed>  $changes  `name` and `is_active`
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $groupId, string $typeId, array $changes, int $expectedVersion): RateType
    {
        return DB::transaction(function () use ($groupId, $typeId, $changes, $expectedVersion): RateType {
            $group = Group::query()->lockForUpdate()->findOrFail($groupId);
            $type = RateType::query()->where('group_id', $groupId)->lockForUpdate()->findOrFail($typeId);
            $before = $this->snapshot($type);

            if (($changes['is_active'] ?? true) === false && $this->usedByCurrentPolicy($groupId, $typeId)) {
                throw ValidationException::withMessages(['is_active' => [__('consolidation.rate_type_in_policy')]]);
            }

            $type->updateLocked(array_intersect_key($changes, array_flip(['name', 'is_active'])), $expectedVersion);

            if ($before !== $this->snapshot($type)) {
                $this->audit->record('group_rate_type.updated', "Group rate type {$type->code} updated.", $type, before: $before, after: $this->snapshot($type), properties: ['group_id' => $groupId], companyId: $group->parent_company_id);
            }

            return $type->refresh();
        });
    }

    private function usedByCurrentPolicy(string $groupId, string $typeId): bool
    {
        $policy = TranslationPolicy::query()->where('group_id', $groupId)->orderByDesc('version')->first();

        return $policy !== null && in_array($typeId, [$policy->closing_rate_type_id, $policy->average_rate_type_id, $policy->historical_rate_type_id], true);
    }

    /** @return array<string, mixed> */
    private function snapshot(RateType $type): array
    {
        return ['code' => $type->code, 'name' => $type->name, 'is_active' => $type->is_active];
    }
}
