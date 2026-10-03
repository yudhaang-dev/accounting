<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * One version of the account a control role of a profile maps to. A change adds a version and never
 * rewrites an earlier one (SL-FR-02, P-04).
 *
 * @property string $id
 * @property string $company_id
 * @property string $posting_profile_id
 * @property string $control_role
 * @property int $version
 * @property string $account_id
 * @property Carbon $effective_date
 * @property int $created_by
 * @property Carbon|null $created_at
 */
class PostingProfileAccount extends CompanyScopedModel
{
    public const UPDATED_AT = null;

    protected $table = 'sl_posting_profile_accounts';

    protected $fillable = ['posting_profile_id', 'control_role', 'version', 'account_id', 'effective_date', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'version' => 'integer'];
    }
}
