<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reopening of a closed fiscal year (GL-FR-14). The run keeps who reopened the year, why, and the
| journal that reversed the closing journal; the year can then be closed again with a new run.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_closing_runs', function (Blueprint $table) {
            CompanyKeys::reference($table, 'reopen_journal_id', 'gl_journals', nullable: true);
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamp('reopened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gl_closing_runs', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'reopen_journal_id']);
            $table->dropColumn(['reopen_journal_id', 'reopened_by', 'reopen_reason', 'reopened_at']);
        });
    }
};
