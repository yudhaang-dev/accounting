<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\PartyRole;
use App\Modules\Subledger\Domain\PartyStatus;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and maintains the party roles of the company (SL-FR-01, ADR-0015). There is no delete: a role
 * that was used is deactivated, and a deactivated role cannot be given to a party again.
 */
final class ManagePartyRoles
{
    public function __construct(private readonly Audit $audit, private readonly PartyRoleTemplates $templates) {}

    /** @throws ValidationException the key is taken */
    public function create(string $key, string $label): PartyRole
    {
        $this->assertFree($key, 'key');

        $role = PartyRole::create(['key' => $key, 'label' => $label])->refresh();
        $this->audit->record('party_role.created', "Party role {$role->key} created.", $role, after: $this->snapshot($role), companyId: $role->company_id);

        return $role;
    }

    /**
     * Creates the roles of the template the company picked, after it confirmed them (X-AC-03). The
     * confirmation is enforced at the HTTP boundary. All are created or none.
     *
     * @param  list<string>  $keys
     * @return list<PartyRole>
     *
     * @throws ValidationException a key outside the template or one the company already has
     */
    public function import(array $keys): array
    {
        $labels = array_column($this->templates->all(), 'label', 'key');
        $errors = [];

        foreach ($keys as $index => $key) {
            if (! isset($labels[$key])) {
                $errors["keys.{$index}"] = [__('party_roles.template_unknown', ['key' => $key])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($keys, $labels): array {
            $taken = PartyRole::query()->whereIn('key', $keys)->pluck('key')->all();

            if ($taken !== []) {
                throw ValidationException::withMessages(['keys' => array_map(fn (string $key): string => __('party_roles.key_taken', ['key' => $key]), $taken)]);
            }

            $roles = array_map(fn (string $key): PartyRole => $this->create($key, $labels[$key]), $keys);
            $this->audit->record('party_roles.template_imported', 'Party roles imported from the template.', properties: ['created' => $keys]);

            return $roles;
        });
    }

    /**
     * Changes the label or the status; the key never changes.
     *
     * @param  array{label?: string, status?: string}  $changes
     */
    public function update(string $roleId, array $changes, int $expectedVersion): PartyRole
    {
        return DB::transaction(function () use ($roleId, $changes, $expectedVersion): PartyRole {
            $role = PartyRole::query()->lockForUpdate()->findOrFail($roleId);
            $before = $this->snapshot($role);

            $role->updateLocked(isset($changes['status']) ? [...$changes, 'status' => PartyStatus::from($changes['status'])] : $changes, $expectedVersion);
            $role = $role->refresh();

            if ($before !== $this->snapshot($role)) {
                $this->audit->record('party_role.updated', "Party role {$role->key} updated.", $role, before: $before, after: $this->snapshot($role), companyId: $role->company_id);
            }

            return $role;
        });
    }

    /** @throws ValidationException */
    private function assertFree(string $key, string $field): void
    {
        if (PartyRole::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages([$field => [__('party_roles.key_taken', ['key' => $key])]]);
        }
    }

    /** @return array{key: string, label: string, status: string} */
    private function snapshot(PartyRole $role): array
    {
        return ['key' => $role->key, 'label' => $role->label, 'status' => $role->status->value];
    }
}
