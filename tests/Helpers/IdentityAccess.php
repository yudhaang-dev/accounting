<?php

use App\Models\User;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Support\Company\CompanyScope;

function scopeAccountId(string $companyId, string $code): string
{
    return (string) Account::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('code', $code)->value('id');
}

function scopeDimension(string $resource, array $body): string
{
    return test()->actingAs(test()->admin)->postJson(test()->url."/{$resource}", $body)->assertCreated()->json('data.id');
}

function scopedMember(string $role, array $valueIds): User
{
    $member = memberWithRoles(test()->companyId, $role);
    limitTo($member, $valueIds)->assertOk();

    return $member;
}

/** Registers a client as the admin; returns the response data, which carries the secret once. */
function registerClient(array $overrides = []): array
{
    return test()->actingAs(test()->admin)->postJson(test()->url.'/integration-clients', array_merge([
        'name' => 'Payroll',
        'source_system' => 'payroll',
        'scopes' => ['transaction-types:read'],
        'transaction_type_keys' => ['payroll_summary'],
    ], $overrides))->assertCreated()->json('data');
}

/** Limits the member to the given dimension values; returns the response. */
function limitTo(User $member, array $valueIds, ?User $actor = null)
{
    $membership = Membership::where('user_id', $member->id)->firstOrFail();

    return test()->actingAs($actor ?? test()->admin)->patchJson(test()->url."/memberships/{$membership->id}", [
        'lock_version' => $membership->lock_version,
        'data_scope' => $valueIds,
    ]);
}
