<?php

namespace App\Modules\IdentityAccess\Domain;

use App\Modules\IdentityAccess\Database\Factories\WorkspaceFactory;
use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $company_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(WorkspaceFactory::class)]
class Workspace extends UlidModel
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $table = 'ia_workspaces';

    protected $fillable = ['name', 'company_id'];

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
