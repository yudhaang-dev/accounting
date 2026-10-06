<?php

namespace App\Modules\Reporting\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Support\Carbon;

/**
 * A named set of rules that maps movement to report rows; the rules live in versions (RP-FR-03).
 * `current_version` is the one that runs; earlier versions stay readable.
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $name
 * @property int $current_version
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MovementClassification extends VersionedCompanyModel
{
    protected $table = 'rp_movement_classifications';

    protected $attributes = ['current_version' => 1];

    protected $fillable = ['key', 'name', 'current_version'];
}
