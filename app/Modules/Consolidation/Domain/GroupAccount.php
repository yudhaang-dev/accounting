<?php

namespace App\Modules\Consolidation\Domain;

use App\Platform\Api\Concerns\HasLockVersion;
use App\Support\Eloquent\UlidModel;
use Illuminate\Support\Carbon;

/**
 * An account of the chart of the group, which the accounts of its companies are mapped to (CO-FR-04). Its class
 * is the element of the statement it belongs to, as for a company account.
 *
 * @property string $id
 * @property string $group_id
 * @property string $code
 * @property string $name
 * @property string $class
 * @property bool $is_active
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GroupAccount extends UlidModel
{
    use HasLockVersion;

    public const CLASSES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $table = 'co_group_accounts';

    protected $fillable = ['group_id', 'code', 'name', 'class', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
