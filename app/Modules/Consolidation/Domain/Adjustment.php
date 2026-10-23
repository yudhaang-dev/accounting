<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A manual adjustment of one run on the accounts of the group (CO-FR-08): where it comes from, why, and who
 * proposed and decided it. Only an `approved` adjustment changes the result of the run, and only the result:
 * no ledger of an entity is ever posted to (CO-AC-04).
 *
 * @property string $id
 * @property string $group_id
 * @property string $run_id
 * @property string $source
 * @property string $reason
 * @property string $status `pending`, `approved` or `rejected`
 * @property int $proposed_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 */
class Adjustment extends UlidModel
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'co_adjustments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return HasMany<AdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AdjustmentLine::class)->orderBy('group_account_code')->orderBy('id');
    }
}
