<?php

namespace App\Modules\IdentityAccess\Contracts;

final readonly class WorkspaceData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $companyId,
        public string $companyName,
    ) {}
}
