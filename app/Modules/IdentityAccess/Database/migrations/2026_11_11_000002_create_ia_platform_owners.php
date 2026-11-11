<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts that may create workspaces (tenants) without holding `workspaces.create` in a role (D2c).
        // The first owner is added when the first-owner invitation is accepted.
        Schema::create('ia_platform_owners', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });

        // A database that already has accounts keeps working: its first account is the owner.
        DB::statement('insert into ia_platform_owners (user_id) select min(id) from users having count(*) > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_platform_owners');
    }
};
