<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use App\Support\Access\DataScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * Reads and replaces the dimension values a membership is limited to (IA-FR-03). Stored values are
 * the ones an admin picked; the descendants are added when the scope is built, so a value added
 * below one of them later falls inside the scope without any change here.
 */
final class MembershipDataScopes
{
    public function __construct(
        private readonly DimensionSets $dimensionSets,
        private readonly DataScopeContext $actorScope,
    ) {}

    /**
     * The scope of the user in the company, built from their membership of its workspace.
     */
    public function forUser(int $userId, string $companyId): DataScope
    {
        $valueIds = DB::table('ia_membership_data_scopes as scope')
            ->join('ia_memberships as membership', 'membership.id', '=', 'scope.membership_id')
            ->where('scope.company_id', $companyId)
            ->where('membership.user_id', $userId)
            ->whereIn('membership.workspace_id', Workspace::query()->where('company_id', $companyId)->select('id'))
            ->pluck('scope.dimension_value_id')
            ->all();

        return $valueIds === []
            ? DataScope::unrestricted()
            : DataScope::restrictedTo($this->dimensionSets->expandValues($companyId, array_values(array_map('strval', $valueIds))));
    }

    /**
     * Value IDs picked for each membership, sorted.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, list<string>>
     */
    public function valueIds(array $membershipIds): array
    {
        $byMembership = array_fill_keys($membershipIds, []);

        $rows = DB::table('ia_membership_data_scopes')
            ->whereIn('membership_id', $membershipIds)
            ->orderBy('dimension_value_id')
            ->get(['membership_id', 'dimension_value_id']);

        foreach ($rows as $row) {
            $byMembership[$row->membership_id][] = $row->dimension_value_id;
        }

        return $byMembership;
    }

    /**
     * Sets the `data_scope` relation of every membership to its list of value IDs.
     *
     * @param  iterable<Membership>  $memberships
     */
    public function attach(iterable $memberships): void
    {
        $memberships = collect($memberships);
        $byMembership = $this->valueIds(array_values(array_map(fn (Membership $membership): string => $membership->id, $memberships->all())));

        foreach ($memberships as $membership) {
            $membership->setRelation('data_scope', collect($byMembership[$membership->id]));
        }
    }

    /**
     * @param  list<string>  $valueIds
     *
     * @throws ApiException DIMENSION_INVALID for a value of another company or one that does not exist
     */
    public function assertValid(string $companyId, array $valueIds): void
    {
        if ($valueIds !== []) {
            $this->dimensionSets->expandValues($companyId, $valueIds);
        }
    }

    /**
     * Replaces the scope; an empty list removes the restriction.
     *
     * @param  list<string>  $valueIds
     *
     * @throws ApiException DIMENSION_INVALID for a value of another company or one that does not exist
     */
    public function replace(string $membershipId, string $companyId, array $valueIds): void
    {
        $valueIds = array_values(array_unique($valueIds));
        $this->assertValid($companyId, $valueIds);

        DB::table('ia_membership_data_scopes')->where('membership_id', $membershipId)->delete();

        DB::table('ia_membership_data_scopes')->insert(array_map(fn (string $valueId): array => [
            'membership_id' => $membershipId,
            'company_id' => $companyId,
            'dimension_value_id' => $valueId,
            'created_at' => now(),
        ], $valueIds));
    }

    /**
     * A user who is themselves limited cannot widen or remove anyone's limit, nor admit someone
     * without one: the limit would stop meaning anything.
     *
     * @throws ApiException FORBIDDEN
     */
    public function assertActorUnrestricted(): void
    {
        if ($this->actorScope->current()->isRestricted()) {
            throw new ApiException(ErrorCode::Forbidden);
        }
    }
}
