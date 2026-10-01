<?php

use App\Modules\IdentityAccess\Domain\Role;
use Spatie\Permission\Models\Permission;

/*
| spatie/laravel-permission in teams mode, team = company (ADR-0004, IA-FR-02).
|
| Spatie only stores roles, permissions and their pivots; the effective permissions of a user are
| read by IdentityAccess itself (AccessContexts), so the Gate check of the package is switched off
| and no global "current team" is needed. There are no direct user permissions: access comes from roles.
*/
return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        'team' => null,
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'ia_roles',
        'permissions' => 'ia_permissions',
        'model_has_permissions' => 'ia_model_has_permissions',
        'model_has_roles' => 'ia_model_has_roles',
        'role_has_permissions' => 'ia_role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'company_id',
    ],

    'register_permission_check_method' => false,

    'teams' => true,

];
