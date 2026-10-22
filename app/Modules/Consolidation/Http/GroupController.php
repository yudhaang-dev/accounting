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

    /** Renames the group. The parent company and the presentation currency do not change after creation. */
    public function update(UpdateGroupRequest $request, ManageGroups $groups, string $group): GroupResource
    {
        return new GroupResource($groups->rename($group, $request->string('name')->toString(), $request->integer('lock_version')));
    }
}
