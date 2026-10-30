<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\Party;
use App\Modules\Subledger\Domain\PartyExternalRef;
use App\Modules\Subledger\Domain\PartyRole;
use App\Modules\Subledger\Domain\PartyRoleAssignment;
use App\Modules\Subledger\Domain\PartyStatus;
use App\Modules\Subledger\Domain\PostingProfile;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains parties and the references source systems use for them (SL-FR-01,
 * SL-AC-08). There is no delete: a party is deactivated and stays resolvable.
 */
final class ManageParties
{
    private const FIELDS = ['name', 'tax_id', 'posting_profile_id', 'counterparty_company_id'];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data  name, roles (keys), tax_id, external_refs
     *
     * @throws ValidationException
     */
    public function create(array $data): Party
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data): Party {
            $this->assertProfile($data['posting_profile_id'] ?? null);
            $party = Party::create(array_intersect_key($data, array_flip(self::FIELDS)));
            $this->syncRoles($party, $data['roles'] ?? []);
            $this->addRefs($party, $data['external_refs'] ?? []);
            $party = $this->loaded($party);

            $this->audit->record('party.created', "Party {$party->name} created.", $party, after: $this->snapshot($party), companyId: $party->company_id);

            return $party;
        }));
    }

    /**
     * References are only added: one that already belongs to this party is ignored, one that
     * belongs to another party is rejected.
     *
     * @param  array<string, mixed>  $data  any of name, roles (keys, the whole set), tax_id, external_refs
     *
     * @throws ApiException VERSION_CONFLICT
     * @throws ValidationException
     */
    public function update(string $partyId, array $data, int $expectedVersion): Party
    {
        return $this->guarded(fn () => DB::transaction(function () use ($partyId, $data, $expectedVersion): Party {
            $party = Party::query()->lockForUpdate()->findOrFail($partyId);
            $before = $this->snapshot($this->loaded($party));

            $changes = array_intersect_key($data, array_flip(self::FIELDS));
            $this->assertProfile($changes['posting_profile_id'] ?? null);

            $party->updateLocked($changes, $expectedVersion);

            // Roles and references are part of the resource, so changing one is a new version even when no field changed.
            $changed = ($this->syncRoles($party, $data['roles'] ?? null) ? 1 : 0) + $this->addRefs($party, $data['external_refs'] ?? []);

            if ($changed > 0 && $party->lock_version === $expectedVersion) {
                $party->lock_version = $expectedVersion + 1;
                $party->save();
            }

            $party = $this->loaded($party);
            $after = $this->snapshot($party);

            if ($before !== $after) {
                $this->audit->record('party.updated', "Party {$party->name} updated.", $party, before: $before, after: $after, companyId: $party->company_id);
            }

            return $party;
        }));
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivate(string $partyId): Party
    {
        return $this->changeStatus($partyId, PartyStatus::Active, PartyStatus::Inactive, 'party.deactivated');
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivate(string $partyId): Party
    {
        return $this->changeStatus($partyId, PartyStatus::Inactive, PartyStatus::Active, 'party.reactivated');
    }

    private function changeStatus(string $partyId, PartyStatus $from, PartyStatus $to, string $event): Party
    {
        return DB::transaction(function () use ($partyId, $from, $to, $event): Party {
            $party = Party::query()->lockForUpdate()->findOrFail($partyId);

            if ($party->status !== $from) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $party->status = $to;
            $party->save();

            $this->audit->record(
                $event,
                "Party {$party->name} changed from {$from->value} to {$to->value}.",
                $party,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $party->company_id,
            );

            return $this->loaded($party);
        });
    }

    /**
     * Makes the roles of the party those of the keys. A role it holds already stays even when it has been
     * deactivated; one it would gain must exist and be active. Null leaves them as they are.
     *
     * @param  list<string>|null  $keys
     * @return bool whether the set changed
     *
     * @throws ValidationException
     */
    private function syncRoles(Party $party, ?array $keys): bool
    {
        if ($keys === null) {
            return false;
        }

        $roles = PartyRole::query()->whereIn('key', $keys)->get()->keyBy('key');
        $held = PartyRoleAssignment::query()->where('party_id', $party->id)->get()->keyBy('party_role_id');
        $errors = [];

        foreach ($keys as $index => $key) {
            $role = $roles->get($key);

            if ($role === null) {
                $errors["roles.{$index}"] = [__('party_roles.unknown', ['key' => $key])];
            } elseif (! $role->isActive() && ! $held->has($role->id)) {
                $errors["roles.{$index}"] = [__('party_roles.inactive', ['key' => $key])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $wanted = $roles->pluck('id')->all();
        $changed = false;

        foreach ($held as $roleId => $assignment) {
            if (! in_array($roleId, $wanted, true)) {
                $assignment->delete();
                $changed = true;
            }
        }

        foreach ($wanted as $roleId) {
            if (! $held->has($roleId)) {
                PartyRoleAssignment::create(['party_id' => $party->id, 'party_role_id' => $roleId]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  list<array{source_system: string, external_ref: string}>  $refs
     * @return int how many references were added
     *
     * @throws ValidationException
     */
    private function addRefs(Party $party, array $refs): int
    {
        $added = 0;

        foreach ($refs as $index => $ref) {
            $existing = PartyExternalRef::query()
                ->where('source_system', $ref['source_system'])
                ->where('external_ref', $ref['external_ref'])
                ->first();

            if ($existing === null) {
                PartyExternalRef::create(['party_id' => $party->id, ...$ref]);
                $added++;
            } elseif ($existing->party_id !== $party->id) {
                throw ValidationException::withMessages([
                    "external_refs.{$index}.external_ref" => [__('parties.ref_taken', $ref)],
                ]);
            }
        }

        return $added;
    }

    /** @throws ValidationException when the profile is not one of this company */
    private function assertProfile(?string $profileId): void
    {
        if ($profileId !== null && ! PostingProfile::query()->whereKey($profileId)->exists()) {
            throw ValidationException::withMessages(['posting_profile_id' => [__('posting_profiles.unknown_profile')]]);
        }
    }

    /** A concurrent request may take the same reference between the check and the insert. */
    private function guarded(callable $work): Party
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_refs' => [__('parties.ref_taken_concurrently')]]);
        }
    }

    private function loaded(Party $party): Party
    {
        return $party->refresh()->load('externalRefs', 'roleAssignments.role');
    }

    /** @return array<string, mixed> */
    private function snapshot(Party $party): array
    {
        return [
            ...$party->only([...self::FIELDS, 'status']),
            'roles' => $party->roleKeys(),
            'external_refs' => $party->externalRefs->map->only(['source_system', 'external_ref'])->all(),
        ];
    }
}
