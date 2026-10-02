<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The approval policy of a company, one row per version (TE-FR-09, P-04), and the approval plan an
| entry is given when it is submitted: what its stages are and who has approved them so far.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('te_approval_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->unsignedInteger('version');
            $table->boolean('separation_of_duties');
            $table->jsonb('rules');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');

            $table->unique(['company_id', 'version']);
        });

        RetentionGuard::protect('te_approval_policies');

        Schema::table('te_entries', function (Blueprint $table) {
            $table->jsonb('approval')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropColumn('approval');
        });

        Schema::dropIfExists('te_approval_policies');
    }
};
