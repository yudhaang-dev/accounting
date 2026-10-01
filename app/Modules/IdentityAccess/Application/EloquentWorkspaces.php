<?php

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Contracts\WorkspaceData;
use App\Modules\IdentityAccess\Contracts\Workspaces;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\Workspace;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EloquentWorkspaces implements Workspaces
{
    public function __construct(
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    public function createFor(int $userId, string $name, string $companyId): WorkspaceData
    {
        $company = $this->companies->find($companyId)
            ?? throw new InvalidArgumentException("Company [{$companyId}] does not exist.");

        return DB::transaction(function () use ($userId, $name, $company): WorkspaceData {
            $workspace = Workspace::create(['name' => $name, 'company_id' => $company->id]);
            $membership = Membership::create(['workspace_id' => $workspace->id, 'user_id' => $userId]);

            $this->audit->record(
                'workspace.created',
                "Workspace {$workspace->name} created.",
                $workspace,
                after: ['name' => $workspace->name, 'company_id' => $workspace->company_id],
                companyId: $company->id,
            );
            $this->audit->record(
                'membership.created',
                'Workspace creator became a member.',
                $membership,
                after: ['workspace_id' => $workspace->id, 'user_id' => $userId],
                companyId: $company->id,
            );

            return new WorkspaceData($workspace->id, $workspace->name, $company->id, $company->name);
        });
    }

    public function accessibleTo(int $userId): array
    {
        $workspaces = Workspace::query()
            ->whereIn('id', Membership::query()->grantingAccess()->where('user_id', $userId)->select('workspace_id'))
            ->orderBy('name')
            ->get();

        $companies = $this->companies->findMany(array_values($workspaces->pluck('company_id')->all()));

        return array_values($workspaces
            ->map(fn (Workspace $workspace): WorkspaceData => new WorkspaceData(
                $workspace->id,
                $workspace->name,
                $workspace->company_id,
                isset($companies[$workspace->company_id]) ? $companies[$workspace->company_id]->name : '',
            ))
            ->all());
    }
}
