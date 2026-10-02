<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Closing of a fiscal year (GL-FR-12). A run records the closing journal and the version of the
| `retained_earnings` mapping it used. A year has one run in force: the unique index covers `closed`
| runs only, so a year that is reopened (GL-FR-14) can be closed again with a new run. A year without
| income or expense activity has a run without a journal.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_closing_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'fiscal_year_id', 'gl_fiscal_years');
            CompanyKeys::reference($table, 'journal_id', 'gl_journals', nullable: true);
            $table->string('status', 16)->default('closed');
            $table->unsignedInteger('retained_earnings_mapping_version');
            $table->unsignedInteger('line_count');
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('created_at');

            $table->index(['company_id', 'fiscal_year_id']);
        });

        DB::statement("ALTER TABLE gl_closing_runs ADD CONSTRAINT gl_closing_runs_status_check CHECK (status IN ('closed', 'reopened'))");
        DB::statement("CREATE UNIQUE INDEX gl_closing_runs_year_closed_unique ON gl_closing_runs (company_id, fiscal_year_id) WHERE status = 'closed'");

        RetentionGuard::protect('gl_closing_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_closing_runs');
    }
};
