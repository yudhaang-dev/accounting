<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Contracts\ControlAccount;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Domain\OpenItem;
use App\Modules\Subledger\Domain\Party;
use App\Modules\Subledger\Domain\PostingProfile;
use App\Modules\Subledger\Domain\PostingProfileAccount;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyScope;

/**
 * Takes the company explicitly, so posting can read it without a request context.
 */
final class EloquentControlAccounts implements ControlAccounts
{
    public function __construct(private readonly ControlAccountRules $rules) {}

    public function require(string $companyId, ControlRole $role, ?string $partyId = null, ?string $asOf = null, ?string $postingProfileId = null): ControlAccount
    {
        $profileId = $postingProfileId ?? $this->profileId($companyId, $partyId);

        if ($profileId === null) {
            throw $this->unmapped($role, __('posting_profiles.no_default'));
        }

        $mapping = PostingProfileAccount::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('posting_profile_id', $profileId)
            ->where('control_role', $role->value)
            ->when($asOf !== null, fn ($query) => $query->whereDate('effective_date', '<=', $asOf))
            ->orderByDesc('version')
            ->first();

        if ($mapping === null) {
            throw $this->unmapped($role, __('posting_profiles.role_unmapped', ['key' => $role->value]));
        }

        $problems = $this->rules->problems($companyId, $mapping->account_id, $role);

        if ($problems !== []) {
            throw new ApiException(ErrorCode::MappingRequired, errors: [$role->value => $problems]);
        }

        return new ControlAccount($role, $mapping->account_id, $profileId, $mapping->version);
    }

    /** @return list<string> */
    public function profileProblems(string $companyId, string $postingProfileId, ControlRole $role): array
    {
        $exists = PostingProfile::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereKey($postingProfileId)->exists();

        if (! $exists) {
            return [__('posting_profiles.unknown_profile')];
        }

        $mapped = PostingProfileAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('posting_profile_id', $postingProfileId)->where('control_role', $role->value)->exists();

        return $mapped ? [] : [__('posting_profiles.role_unmapped', ['key' => $role->value])];
    }

    /**
     * The profile that decides the control account of an open item: the one its route named, else the profile of
     * its party, else the default.
     */
    public function profileOf(OpenItem $item): ?string
    {
        return $item->posting_profile_id ?? $this->profileId($item->company_id, $item->party_id);
    }

    private function profileId(string $companyId, ?string $partyId): ?string
    {
        $profileId = $partyId === null ? null : Party::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereKey($partyId)->firstOrFail()->posting_profile_id;

        return $profileId ?? PostingProfile::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('is_default', true)->value('id');
    }

    private function unmapped(ControlRole $role, string $message): ApiException
    {
        return new ApiException(ErrorCode::MappingRequired, errors: [$role->value => [$message]]);
    }
}
