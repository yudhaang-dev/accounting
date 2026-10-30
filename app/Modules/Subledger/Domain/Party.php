<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Whoever the company has receivables from or payables to, with the roles it holds (SL-FR-01). Never
 * deleted: a party that was used is only deactivated.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string|null $tax_id
 * @property string|null $posting_profile_id
 * @property string|null $counterparty_company_id
 * @property PartyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Party extends VersionedCompanyModel
{
    protected $table = 'sl_parties';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'tax_id', 'posting_profile_id', 'counterparty_company_id', 'status'];

    protected function casts(): array
    {
        return ['status' => PartyStatus::class];
    }

    /** @return HasMany<PartyRoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(PartyRoleAssignment::class);
    }

    /** @return HasMany<PartyExternalRef, $this> */
    public function externalRefs(): HasMany
    {
        return $this->hasMany(PartyExternalRef::class)->orderBy('source_system')->orderBy('external_ref');
    }

    /**
     * The keys of the roles it holds, in order; needs `roleAssignments.role` loaded.
     *
     * @return list<string>
     */
    public function roleKeys(): array
    {
        $keys = [];

        foreach ($this->roleAssignments as $assignment) {
            $keys[] = $assignment->role->key;
        }

        sort($keys);

        return $keys;
    }

    public function isActive(): bool
    {
        return $this->status === PartyStatus::Active;
    }
}
