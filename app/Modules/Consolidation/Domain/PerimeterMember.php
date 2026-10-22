<?php

namespace App\Modules\Consolidation\Domain;

use App\Platform\Api\Concerns\HasLockVersion;
use App\Support\Eloquent\UlidModel;
use Illuminate\Support\Carbon;

/**
 * A company in the perimeter of a group, with the reasoning of the control assessment and who approved it
 * (CO-FR-02). Only `approved` members count for a consolidation.
 *
 * @property string $id
 * @property string $group_id
 * @property string $company_id
 * @property string $control_basis
 * @property string $treatment
 * @property string $status `pending`, `approved` or `ended`
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $proposed_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $ended_by
 * @property Carbon|null $ended_at
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PerimeterMember extends UlidModel
{
    use HasLockVersion;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const ENDED = 'ended';

    protected $table = 'co_perimeter_members';

    protected $fillable = ['group_id', 'company_id', 'control_basis', 'treatment', 'status', 'effective_from', 'effective_to', 'proposed_by', 'approved_by', 'approved_at', 'ended_by', 'ended_at'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
