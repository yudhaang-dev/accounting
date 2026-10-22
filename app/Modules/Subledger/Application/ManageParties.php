<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\Party;
use App\Modules\Subledger\Domain\PartyExternalRef;
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
    private const FIELDS = ['name', 'is_customer', 'is_supplier', 'tax_id', 'posting_profile_id', 'counterparty_company_id'];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data  name, is_customer, is_supplier, tax_id, external_refs
     *
     * @throws ValidationException
     */
    public function create(array $data): Party
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data): Party {
            $this->assertProfile($data['posting_profile_id'] ?? null);
            $party = Party::create(array_intersect_key($data, array_flip(self::FIELDS)));
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
     * @param  array<string, mixed>  $data  any of name, is_customer, is_supplier, tax_id, external_refs
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

            if (! ($changes['is_customer'] ?? $party->is_customer) && ! ($changes['is_supplier'] ?? $party->is_supplier)) {
                throw ValidationException::withMessages(['is_customer' => [__('parties.role_required')]]);
            }

            $party->updateLocked($changes, $expectedVersion);

            // References are part of the resource, so adding one is a new version even when no field changed.
            if ($this->addRefs($party, $data['external_refs'] ?? []) > 0 && $party->lock_version === $expectedVersion) {
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
        return $party->refresh()->load('externalRefs');
    }

    /** @return array<string, mixed> */
    private function snapshot(Party $party): array
    {
        return [
            ...$party->only([...self::FIELDS, 'status']),
            'external_refs' => $party->externalRefs->map->only(['source_system', 'external_ref'])->all(),
        ];
    }
}
