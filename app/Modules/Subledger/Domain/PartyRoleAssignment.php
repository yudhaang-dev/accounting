<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A role held by a party (SL-FR-01).
 *
 * @property string $id
 * @property string $company_id
 * @property string $party_id
 * @property string $party_role_id
 * @property Carbon|null $created_at
 */
class PartyRoleAssignment extends CompanyScopedModel
{
    public const UPDATED_AT = null;

    protected $table = 'sl_party_role_assignments';

    protected $fillable = ['party_id', 'party_role_id'];

    /** @return BelongsTo<PartyRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(PartyRole::class, 'party_role_id');
    }
}
