<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use App\Modules\Consolidation\Domain\MappingRow;
use App\Modules\Consolidation\Domain\MappingVersion;
use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\Reporting\Contracts\ReportPackageAccount;
use App\Modules\Reporting\Contracts\ReportPackages;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The mapping of company accounts to the chart of the group, versioned as a whole (CO-FR-04, P-04). Saving it
 * adds a version with every row; earlier versions stay readable so a run can name the one it used (CO-FR-09).
 * An account of a company can only be mapped for an approved member, to an active group account of the same
 * class, and once per version.
 */
final class ManageMapping
{
    public function __construct(
        private readonly ReportPackages $packages,
        private readonly Audit $audit,
    ) {}

    /** The latest version with its rows, or null when the group has never been mapped. */
    public function current(string $groupId): ?MappingVersion
    {
        return MappingVersion::query()->where('group_id', $groupId)->orderByDesc('version')->first();
    }

    /**
     * @param  list<array{company_id: string, account_id: string, group_account_id: string}>  $mappings  the whole mapping as it should be from now on
     * @param  int  $expectedVersion  the version the client read, 0 when none
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function replace(string $groupId, array $mappings, string $effectiveDate, int $expectedVersion, int $userId): MappingVersion
    {
        return DB::transaction(function () use ($groupId, $mappings, $effectiveDate, $expectedVersion, $userId): MappingVersion {
            // The lock serializes savers of one group, so the versions have no gaps or repeats.
            $group = Group::query()->lockForUpdate()->findOrFail($groupId);
            $latest = (int) MappingVersion::query()->where('group_id', $groupId)->max('version');

            if ($latest !== $expectedVersion) {
                throw new ApiException(ErrorCode::VersionConflict);
            }

            $codes = $this->validate($groupId, $mappings);

            $version = MappingVersion::create(['group_id' => $groupId, 'version' => $latest + 1, 'effective_date' => $effectiveDate, 'created_by' => $userId])->refresh();

            foreach ($mappings as $mapping) {
                MappingRow::create([
                    'mapping_version_id' => $version->id,
                    'company_id' => $mapping['company_id'],
                    'account_id' => $mapping['account_id'],
                    'account_code' => $codes["{$mapping['company_id']}:{$mapping['account_id']}"],
                    'group_account_id' => $mapping['group_account_id'],
                ]);
            }

            $this->audit->record(
                'group_mapping.saved',
                "Account mapping of group {$group->name} saved as version {$version->version}.",
                $version,
                after: ['version' => $version->version, 'rows' => count($mappings), 'effective_date' => $effectiveDate],
                properties: ['group_id' => $groupId],
                companyId: $group->parent_company_id,
            );

            return $version;
        });
    }

    /**
     * @param  list<array{company_id: string, account_id: string, group_account_id: string}>  $mappings
     * @return array<string, string> "company:account" => the code of the company account
     *
     * @throws ValidationException
     */
    private function validate(string $groupId, array $mappings): array
    {
        $members = PerimeterMember::query()->where('group_id', $groupId)->where('status', PerimeterMember::APPROVED)->pluck('company_id')->all();
        $groupAccounts = GroupAccount::query()->where('group_id', $groupId)->where('is_active', true)->get()->keyBy('id');
        /** @var array<string, array<string, ReportPackageAccount>> $accounts */
        $accounts = [];
        $codes = [];
        $seen = [];
        $errors = [];

        foreach ($mappings as $index => $mapping) {
            $company = $mapping['company_id'];
            $key = "{$company}:{$mapping['account_id']}";

            if (! in_array($company, $members, true)) {
                $errors["mappings.{$index}.company_id"] = [__('consolidation.mapping_company_not_member')];

                continue;
            }

            $accounts[$company] ??= collect($this->packages->accounts($company))->keyBy('id')->all();
            $account = $accounts[$company][$mapping['account_id']] ?? null;
            $groupAccount = $groupAccounts[$mapping['group_account_id']] ?? null;

            if ($account === null) {
                $errors["mappings.{$index}.account_id"] = [__('consolidation.mapping_account_unknown')];
            }

            if ($groupAccount === null) {
                $errors["mappings.{$index}.group_account_id"] = [__('consolidation.mapping_group_account_unknown')];
            }

            if ($account !== null && $groupAccount !== null && $account->class !== $groupAccount->class) {
                $errors["mappings.{$index}.group_account_id"] = [__('consolidation.mapping_class_mismatch', ['account' => $account->class, 'group' => $groupAccount->class])];
            }

            if (isset($seen[$key])) {
                $errors["mappings.{$index}.account_id"] = [__('consolidation.mapping_duplicate')];
            }

            $seen[$key] = true;
            $codes[$key] = $account->code ?? '';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $codes;
    }
}
