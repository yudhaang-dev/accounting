<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The first-owner invitation (ADR-0021) belongs to no workspace and has no inviter: it only creates the account.
        DB::statement('alter table ia_invitations alter column workspace_id drop not null');
        DB::statement('alter table ia_invitations alter column invited_by drop not null');
    }

    public function down(): void
    {
        DB::statement('delete from ia_invitations where workspace_id is null');
        DB::statement('alter table ia_invitations alter column workspace_id set not null');
        DB::statement('alter table ia_invitations alter column invited_by set not null');
    }
};
