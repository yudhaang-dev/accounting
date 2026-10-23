<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Application\ManageGroups;
use App\Modules\Consolidation\Domain\Group;

class GroupController
{
    /** The group with its `lock_version`. Needs a read grant on the group (IA-FR-07). */
    public function show(string $group): GroupResource
    {
        return new GroupResource(Group::query()->findOrFail($group));
    }

    /** Renames the group, sets its package policy or the tolerance of intragroup eliminations (in the presentation currency). The parent company and the presentation currency do not change after creation. */
    public function update(UpdateGroupRequest $request, ManageGroups $groups, string $group): GroupResource
    {
        return new GroupResource($groups->update($group, $request->safe()->only(['name', 'package_policy', 'elimination_tolerance']), $request->integer('lock_version')));
    }
}
