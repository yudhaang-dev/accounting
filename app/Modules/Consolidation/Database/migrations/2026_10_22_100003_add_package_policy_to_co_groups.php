<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| What a run does with a package that is not closed or does not cover its range (CO-FR-03): the group decides
| whether such a run is kept as a draft or refused.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('co_groups', function (Blueprint $table) {
            $table->string('package_policy', 16)->default('draft');
        });

        DB::statement("ALTER TABLE co_groups ADD CONSTRAINT co_groups_package_policy_check CHECK (package_policy IN ('draft', 'reject'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE co_groups DROP CONSTRAINT co_groups_package_policy_check');

        Schema::table('co_groups', function (Blueprint $table) {
            $table->dropColumn('package_policy');
        });
    }
};
