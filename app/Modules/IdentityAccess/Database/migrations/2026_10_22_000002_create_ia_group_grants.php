<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Explicit access of a user to a consolidation group (IA-FR-07). `group_id` is an opaque ID of the
| Consolidation module: access to several workspaces never forms or implies a grant. There is one
| row per ability, so a user may read a group's reports without being allowed to run a consolidation.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_group_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('group_id');
            $table->foreignId('user_id')->constrained('users');
            $table->string('ability', 16);
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['group_id', 'user_id', 'ability']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE ia_group_grants ADD CONSTRAINT ia_group_grants_ability_check CHECK (ability IN ('read', 'run'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_group_grants');
    }
};
