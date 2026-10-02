<?php

namespace App\Modules\IdentityAccess\Domain;

use App\Modules\IdentityAccess\Database\Factories\RoleFactory;
use App\Platform\Api\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role of a company: a named set of permissions (IA-FR-02). Spatie stores it with `company_id`
 * as the team key. A deactivated role grants nothing.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 */
#[UseFactory(RoleFactory::class)]
class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasLockVersion, HasUlids;

    /**
     * Keys of the permissions of the role, sorted.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        $keys = array_map(fn (Permission $permission): string => $permission->name, $this->permissions->all());
        sort($keys);

        return $keys;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
