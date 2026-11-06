<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Domain\CashAccount;
use App\Modules\Subledger\Domain\PostingProfile;
use App\Modules\Subledger\Domain\PostingProfileAccount;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates posting profiles and maps their control roles to accounts as new versions (SL-FR-02).
 * The first profile of a company is its default; the default is moved, never cleared.
 */
final class ManagePostingProfiles
{
    public function __construct(
        private readonly Audit $audit,
        private readonly ControlAccountRules $rules,
    ) {}

    /**
     * @param  array{name: string, is_default?: bool}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): PostingProfile
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data): PostingProfile {
            $makeDefault = ! PostingProfile::query()->exists() || ($data['is_default'] ?? false);

            if ($makeDefault) {
                $this->clearDefault();
            }

            $profile = PostingProfile::create(['name' => $data['name'], 'is_default' => $makeDefault])->refresh();

            $this->audit->record('posting_profile.created', "Posting profile {$profile->name} created.", $profile, after: $profile->only(['name', 'is_default']), companyId: $profile->company_id);

            return $profile;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  any of name, is_default
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $profileId, array $data, int $expectedVersion): PostingProfile
    {
        return $this->guarded(fn () => DB::transaction(function () use ($profileId, $data, $expectedVersion): PostingProfile {
            $profile = PostingProfile::query()->lockForUpdate()->findOrFail($profileId);
            $changes = array_intersect_key($data, array_flip(['name', 'is_default']));

            if (($changes['is_default'] ?? null) === false && $profile->is_default) {
                throw ValidationException::withMessages(['is_default' => [__('posting_profiles.default_required')]]);
            }

            if (($changes['is_default'] ?? false) && ! $profile->is_default) {
                $this->clearDefault();
            }

            $before = $profile->only(array_keys($changes));
            $profile->updateLocked($changes, $expectedVersion);
            $after = $profile->only(array_keys($changes));

            if ($before !== $after) {
                $this->audit->record('posting_profile.updated', "Posting profile {$profile->name} updated.", $profile, before: $before, after: $after, companyId: $profile->company_id);
            }

            return $profile->refresh();
        }));
    }

    /**
     * Maps the control role of the profile to an account as a new version. `$expectedVersion` is the
     * latest version of that role in the profile (0 when it was never mapped).
     *
     * @param  string|null  $effectiveDate  `YYYY-MM-DD`; today when null, and all dates for the first mapping
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException on `account_id` for an invalid target
     */
    public function mapAccount(string $profileId, ControlRole $role, string $accountId, int $expectedVersion, int $actorId, ?string $effectiveDate = null): PostingProfileAccount
    {
        try {
            return DB::transaction(function () use ($profileId, $role, $accountId, $expectedVersion, $actorId, $effectiveDate): PostingProfileAccount {
                $profile = PostingProfile::query()->lockForUpdate()->findOrFail($profileId);

                $problems = $this->rules->problems($profile->company_id, $accountId, $role);

                if (CashAccount::query()->where('account_id', $accountId)->exists()) {
                    $problems[] = __('posting_profiles.account_is_cash');
                }

                if ($problems !== []) {
                    throw ValidationException::withMessages(['account_id' => $problems]);
                }

                $before = $this->latest($profile->id, $role);

                if (($before->version ?? 0) !== $expectedVersion) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                $after = PostingProfileAccount::create([
                    'posting_profile_id' => $profile->id,
                    'control_role' => $role->value,
                    'version' => $expectedVersion + 1,
                    'account_id' => $accountId,
                    'effective_date' => $effectiveDate ?? ($before === null ? '1970-01-01' : now()->toDateString()),
                    'created_by' => $actorId,
                ]);

                $this->audit->record(
                    'posting_profile.account_changed',
                    "Control role {$role->value} of posting profile {$profile->name} mapped (version {$after->version}).",
                    $profile,
                    before: $before === null ? [] : ['account_id' => $before->account_id, 'version' => $before->version],
                    after: ['account_id' => $after->account_id, 'version' => $after->version, 'effective_date' => $after->effective_date->toDateString()],
                    properties: ['control_role' => $role->value],
                    companyId: $profile->company_id,
                );

                return $after;
            });
        } catch (UniqueConstraintViolationException) {
            // Another editor added the same version first.
            throw new ApiException(ErrorCode::VersionConflict);
        }
    }

    private function latest(string $profileId, ControlRole $role): ?PostingProfileAccount
    {
        return PostingProfileAccount::query()
            ->where('posting_profile_id', $profileId)
            ->where('control_role', $role->value)
            ->orderByDesc('version')
            ->first();
    }

    private function clearDefault(): void
    {
        PostingProfile::query()->where('is_default', true)->get()->each(function (PostingProfile $current): void {
            $current->is_default = false;
            $current->save();
        });
    }

    /** The name is unique per company; a concurrent request may take it between check and insert. */
    private function guarded(callable $work): PostingProfile
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [__('posting_profiles.name_taken')]]);
        }
    }
}
