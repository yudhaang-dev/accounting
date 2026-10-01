<?php

namespace App\Orchestration\Workspace;

use App\Modules\IdentityAccess\Contracts\WorkspaceData;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\NewCompany;
use Illuminate\Support\Facades\DB;

/**
 * Creating a workspace creates its company in status `setup` and makes the creator its first
 * member, in one transaction (IA-FR-01).
 */
final class CreateWorkspaceWithCompany
{
    public function __construct(
        private readonly CompanyDirectory $companies,
        private readonly Workspaces $workspaces,
    ) {}

    public function __invoke(int $userId, string $workspaceName, NewCompany $company): WorkspaceData
    {
        return DB::transaction(function () use ($userId, $workspaceName, $company): WorkspaceData {
            $created = $this->companies->create($company);

            return $this->workspaces->createFor($userId, $workspaceName, $created->id);
        });
    }
}
