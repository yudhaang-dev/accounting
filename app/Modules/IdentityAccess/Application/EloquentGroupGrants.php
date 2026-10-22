<?php

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Contracts\GroupAbility;
use App\Modules\IdentityAccess\Contracts\GroupGrants;
use App\Modules\IdentityAccess\Domain\GroupGrant;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

final class EloquentGroupGrants implements GroupGrants
{
    public function __construct(private readonly Audit $audit) {}

    public function can(int $userId, string $groupId, GroupAbility $ability): bool
    {
        return GroupGrant::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->whereIn('ability', $this->covering($ability))
            ->exists();
    }

    public function grant(int $userId, string $groupId, GroupAbility $ability, ?int $grantedBy = null): void
    {
        DB::transaction(function () use ($userId, $groupId, $ability, $grantedBy): void {
            $grant = GroupGrant::query()->firstOrCreate(
                ['group_id' => $groupId, 'user_id' => $userId, 'ability' => $ability->value],
                ['granted_by' => $grantedBy],
            );

            if ($grant->wasRecentlyCreated) {
                $this->audit->record(
                    'group_grant.created',
                    "User {$userId} granted {$ability->value} on group {$groupId}.",
                    $grant,
                    after: ['ability' => $ability->value],
                    actor: $grantedBy === null ? null : User::find($grantedBy),
                );
            }
        });
    }

    public function revoke(int $userId, string $groupId, GroupAbility $ability): void
    {
        DB::transaction(function () use ($userId, $groupId, $ability): void {
            GroupGrant::query()
                ->where('group_id', $groupId)
                ->where('user_id', $userId)
                ->whereIn('ability', $this->covering($ability))
                ->get()
                ->each(function (GroupGrant $grant): void {
                    $grant->delete();

                    $this->audit->record(
                        'group_grant.revoked',
                        "User {$grant->user_id} lost {$grant->ability} on group {$grant->group_id}.",
                        $grant,
                        before: ['ability' => $grant->ability],
                    );
                });
        });
    }

    /**
     * The stored abilities that include the asked one: running includes reading.
     *
     * @return list<string>
     */
    private function covering(GroupAbility $ability): array
    {
        return $ability === GroupAbility::Read ? ['read', 'run'] : ['run'];
    }
}
