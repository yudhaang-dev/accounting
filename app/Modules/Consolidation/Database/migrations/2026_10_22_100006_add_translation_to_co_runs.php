<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| What a run keeps of its translation (CO-FR-06, CO-FR-09): the version of the policy it used, for each translated
| entity the rates it read (type, date asked, date found, version, value), and for each balance the amounts in
| the functional currency it was translated from. With these a translated figure can be traced back and
| reproduced without the ledger or the rates of the day.
|
| The row that takes the translation difference of an entity is a balance of kind `translation_difference`, on
| the group account the policy names; all other rows are of kind `account`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('co_runs', function (Blueprint $table) {
            $table->unsignedInteger('translation_policy_version')->nullable()->after('mapping_version');
        });

        Schema::table('co_run_entities', function (Blueprint $table) {
            $table->jsonb('translation')->nullable();
        });

        Schema::table('co_run_balances', function (Blueprint $table) {
            $table->string('kind', 24)->default('account');
            $table->jsonb('translation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('co_run_balances', function (Blueprint $table) {
            $table->dropColumn(['kind', 'translation']);
        });

        Schema::table('co_run_entities', function (Blueprint $table) {
            $table->dropColumn('translation');
        });

        Schema::table('co_runs', function (Blueprint $table) {
            $table->dropColumn('translation_policy_version');
        });
    }
};
