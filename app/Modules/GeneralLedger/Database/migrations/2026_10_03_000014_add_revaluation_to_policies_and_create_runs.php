<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Revaluation of foreign currency balances (GL-FR-09). The ledger policy names the rate type of the
| closing rate and whether the result is reversed at the start of the next period. A run records the
| journal it posted, the reversal and the versions of the role mappings it used; a run that finds
| nothing to adjust posts and records nothing.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_ledger_policies', function (Blueprint $table) {
            $table->string('revaluation_rate_type_key', 64)->nullable();
            $table->boolean('revaluation_auto_reverse')->default(false);
        });

        Schema::create('gl_revaluation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'period_id', 'gl_periods');
            CompanyKeys::reference($table, 'journal_id', 'gl_journals');
            CompanyKeys::reference($table, 'reversal_journal_id', 'gl_journals', nullable: true);
            $table->string('rate_type_key', 64);
            $table->unsignedInteger('gain_mapping_version');
            $table->unsignedInteger('loss_mapping_version');
            $table->unsignedBigInteger('run_by');
            $table->timestamp('created_at');

            $table->index(['company_id', 'period_id']);
        });

        RetentionGuard::protect('gl_revaluation_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_revaluation_runs');
        Schema::table('gl_ledger_policies', fn (Blueprint $table) => $table->dropColumn(['revaluation_rate_type_key', 'revaluation_auto_reverse']));
    }
};
