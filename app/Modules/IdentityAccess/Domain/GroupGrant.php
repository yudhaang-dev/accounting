<?php

namespace App\Modules\IdentityAccess\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $group_id
 * @property int $user_id
 * @property string $ability
 * @property int|null $granted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GroupGrant extends UlidModel
{
    protected $table = 'ia_group_grants';

    protected $fillable = ['group_id', 'user_id', 'ability', 'granted_by'];
}
