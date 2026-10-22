<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use App\Modules\Consolidation\Domain\RateType;
use App\Modules\Consolidation\Domain\TranslationPolicy;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The translation policy of a group, versioned as a whole (CO-FR-06, P-04): saving it adds a version, and
 * earlier versions stay readable so a run can name the one it used (CO-FR-09). The three rate types must be
 * active types of the group, and the difference goes to an active equity account of the group chart, which
 * is then reserved for translation: nothing is mapped to it (see ManageMapping).
 */
final class ManageTranslationPolicy
{
    public function __construct(private readonly Audit $audit) {}

    /** The latest version, or null when the group has no policy. */
    public function current(string $groupId): ?TranslationPolicy
    {
        return TranslationPolicy::query()->where('group_id', $groupId)->orderByDesc('version')->first();
    }

    /**
     * @param  array{closing_rate_type_id: string, average_rate_type_id: string, historical_rate_type_id: string, difference_account_id: string}  $policy
     * @param  int  $expectedVersion  the version the client read, 0 when none
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function replace(string $groupId, array $policy, string $effectiveDate, int $expectedVersion, int $userId): TranslationPolicy
    {
        return DB::transaction(function () use ($groupId, $policy, $effectiveDate, $expectedVersion, $userId): TranslationPolicy {
            // The lock serializes savers of one group, so the versions have no gaps or repeats.
            $group = Group::query()->lockForUpdate()->findOrFail($groupId);
            $latest = (int) TranslationPolicy::query()->where('group_id', $groupId)->max('version');

            if ($latest !== $expectedVersion) {
                throw new ApiException(ErrorCode::VersionConflict);
            }

            $this->validate($groupId, $policy);

            $version = TranslationPolicy::create([...$policy, 'group_id' => $groupId, 'version' => $latest + 1, 'effective_date' => $effectiveDate, 'created_by' => $userId])->refresh();

            $this->audit->record(
                'group_translation_policy.saved',
                "Translation policy of group {$group->name} saved as version {$version->version}.",
                $version,
                after: [...$policy, 'version' => $version->version, 'effective_date' => $effectiveDate],
                properties: ['group_id' => $groupId],
                companyId: $group->parent_company_id,
            );

            return $version;
        });
    }

    /**
     * @param  array{closing_rate_type_id: string, average_rate_type_id: string, historical_rate_type_id: string, difference_account_id: string}  $policy
     *
     * @throws ValidationException
     */
    private function validate(string $groupId, array $policy): void
    {
        $errors = [];
        $active = RateType::query()->where('group_id', $groupId)->where('is_active', true)->pluck('id')->all();

        foreach (['closing_rate_type_id', 'average_rate_type_id', 'historical_rate_type_id'] as $field) {
            if (! in_array($policy[$field], $active, true)) {
                $errors[$field] = [__('consolidation.policy_rate_type_unknown')];
            }
        }

        $account = GroupAccount::query()->where('group_id', $groupId)->where('is_active', true)->find($policy['difference_account_id']);

        if ($account === null) {
            $errors['difference_account_id'] = [__('consolidation.mapping_group_account_unknown')];
        } elseif ($account->class !== 'equity') {
            $errors['difference_account_id'] = [__('consolidation.policy_difference_not_equity')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
