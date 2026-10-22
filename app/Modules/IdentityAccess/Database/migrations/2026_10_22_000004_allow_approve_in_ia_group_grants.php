<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The ability to approve the control assessment of a group's members (CO-FR-02) is held apart from running
| the group, so the approver need not be the one who prepared the perimeter.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ia_group_grants DROP CONSTRAINT ia_group_grants_ability_check');
        DB::statement("ALTER TABLE ia_group_grants ADD CONSTRAINT ia_group_grants_ability_check CHECK (ability IN ('read', 'run', 'approve'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ia_group_grants WHERE ability = 'approve'");
        DB::statement('ALTER TABLE ia_group_grants DROP CONSTRAINT ia_group_grants_ability_check');
        DB::statement("ALTER TABLE ia_group_grants ADD CONSTRAINT ia_group_grants_ability_check CHECK (ability IN ('read', 'run'))");
    }
};
