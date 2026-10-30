<?php

namespace App\Modules\Subledger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Support\Carbon;

/**
 * What a party is to the company, such as customer, employee or shareholder (SL-FR-01, ADR-0015). A role
 * is data of the company with a stable `key`; one that was used is only deactivated. It says nothing
 * about the side of the ledger: the posting profile does.
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $label
 * @property PartyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PartyRole extends VersionedCompanyModel
{
    protected $table = 'sl_party_roles';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['key', 'label', 'status'];

    public function isActive(): bool
    {
        return $this->status === PartyStatus::Active;
    }

    protected function casts(): array
    {
        return ['status' => PartyStatus::class];
    }
}
