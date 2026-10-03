<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * How a source system refers to a party of its own (SL-FR-01). The pair (`source_system`,
 * `external_ref`) identifies one party in the company (SL-AC-08).
 *
 * @property string $id
 * @property string $company_id
 * @property string $party_id
 * @property string $source_system
 * @property string $external_ref
 * @property Carbon|null $created_at
 */
class PartyExternalRef extends CompanyScopedModel
{
    public const UPDATED_AT = null;

    protected $table = 'sl_party_external_refs';

    protected $fillable = ['party_id', 'source_system', 'external_ref'];
}
