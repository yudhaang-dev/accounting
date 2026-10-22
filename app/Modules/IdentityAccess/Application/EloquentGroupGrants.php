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
            ->whereIn('ability', $ability->coveredBy())
            ->exists();
    }

    public function abilitiesOf(int $userId, string $groupId): array
    {
        $held = GroupGrant::query()->where('group_id', $groupId)->where('user_id', $userId)->pluck('ability')->map(fn (mixed $ability): string => (string) $ability)->all();

        return $held === [] ? [] : array_values(array_unique(['read', ...$held]));
    }

    public function groupsOf(int $userId): array
    {
        return array_values(array_map(strval(...), GroupGrant::query()->where('user_id', $userId)->distinct()->orderBy('group_id')->pluck('group_id')->all()));
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
                ->whereIn('ability', $ability->coveredBy())
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
}
