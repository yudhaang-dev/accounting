<?php

namespace App\Modules\IdentityAccess\Contracts;

final readonly class WorkspaceData
{
    /** @param  list<string>  $permissions  effective permissions of the requesting user in the workspace company */
    public function __construct(
        public string $id,
        public string $name,
        public string $companyId,
        public string $companyName,
        public array $permissions = [],
    ) {}
}
