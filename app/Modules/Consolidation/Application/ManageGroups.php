<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Contracts\GroupData;
use App\Modules\Consolidation\Contracts\Groups;
use App\Modules\Consolidation\Contracts\PerimeterMemberData;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains groups (CO-FR-02). The presentation currency is fixed at creation: results of a
 * consolidation are stated in it, so changing it would change what past runs mean.
 */
final class ManageGroups implements Groups
{
    public function __construct(
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
        private readonly ManagePerimeter $perimeter,
        private readonly Audit $audit,
    ) {}

    public function create(string $name, string $parentCompanyId, string $presentationCurrency, int $createdBy): GroupData
    {
        $currency = strtoupper($presentationCurrency);

        if (! $this->currencies->isKnown($currency)) {
            throw ValidationException::withMessages(['presentation_currency' => [__('consolidation.currency_unknown')]]);
        }

        if ($this->companies->find($parentCompanyId) === null) {
            throw ValidationException::withMessages(['parent_company_id' => [__('consolidation.company_unknown')]]);
        }

        return DB::transaction(function () use ($name, $parentCompanyId, $currency, $createdBy): GroupData {
            $group = Group::create([
                'name' => $name,
                'parent_company_id' => $parentCompanyId,
                'presentation_currency' => $currency,
                'created_by' => $createdBy,
            ])->refresh();

            $this->perimeter->seedParent($group, $createdBy);

            $this->audit->record('group.created', "Consolidation group {$group->name} created.", $group, after: $this->snapshot($group), companyId: $parentCompanyId);

            return $this->data($group);
        });
    }

    /**
     * @throws ApiException VERSION_CONFLICT
     */
    public function rename(string $groupId, string $name, int $expectedVersion): Group
    {
        return DB::transaction(function () use ($groupId, $name, $expectedVersion): Group {
            $group = Group::query()->lockForUpdate()->findOrFail($groupId);
            $before = $this->snapshot($group);

            $group->updateLocked(['name' => $name], $expectedVersion);

            if ($before !== $this->snapshot($group)) {
                $this->audit->record('group.updated', "Consolidation group {$group->name} renamed.", $group, before: $before, after: $this->snapshot($group), companyId: $group->parent_company_id);
            }

            return $group->refresh();
        });
    }

    public function find(string $groupId): ?GroupData
    {
        $group = Group::query()->find($groupId);

        return $group === null ? null : $this->data($group);
    }

    public function findMany(array $groupIds): array
    {
        return array_values(Group::query()->whereIn('id', $groupIds)->orderBy('name')->get()->map($this->data(...))->all());
    }

    public function proposeMember(string $groupId, string $companyId, string $controlBasis, string $effectiveFrom, int $proposedBy): PerimeterMemberData
    {
        return $this->perimeter->data($this->perimeter->propose($groupId, $companyId, $controlBasis, $effectiveFrom, $proposedBy));
    }

    private function data(Group $group): GroupData
    {
        return new GroupData($group->id, $group->name, $group->parent_company_id, $group->presentation_currency, $group->lock_version);
    }

    /** @return array<string, mixed> */
    private function snapshot(Group $group): array
    {
        return ['name' => $group->name, 'parent_company_id' => $group->parent_company_id, 'presentation_currency' => $group->presentation_currency];
    }
}
