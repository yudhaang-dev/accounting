<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer, a supplier or both, as the subledger knows it (SL-FR-01). Never deleted: a party
 * that was used is only deactivated.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property bool $is_customer
 * @property bool $is_supplier
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

    protected $attributes = ['status' => 'active', 'is_customer' => false, 'is_supplier' => false];

    protected $fillable = ['name', 'is_customer', 'is_supplier', 'tax_id', 'posting_profile_id', 'counterparty_company_id', 'status'];

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'status' => PartyStatus::class,
        ];
    }

    /** @return HasMany<PartyExternalRef, $this> */
    public function externalRefs(): HasMany
    {
        return $this->hasMany(PartyExternalRef::class)->orderBy('source_system')->orderBy('external_ref');
    }

    public function isActive(): bool
    {
        return $this->status === PartyStatus::Active;
    }
}
