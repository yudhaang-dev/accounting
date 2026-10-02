<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Opening balance batches (GL-FR-11). A batch is the record of one import: the dates, the source key
| that makes the import idempotent, the mapping of source codes that was used and the journal that
| carries the balances. A company has at most one batch; importing the same source key and content
| again finds it instead of posting twice (GL-AC-07).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_opening_balance_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('source_key', 100);
            $table->string('source_system', 64)->nullable();
            $table->string('payload_hash', 64);
            $table->date('cutover_date');
            $table->date('ledger_start_date');
            // Detail of the history between `ledger_start_date` and `cutover_date`; set by the history import.
            $table->string('history_granularity', 16)->nullable();
            $table->string('status', 16)->default('posted');
            CompanyKeys::reference($table, 'journal_id', 'gl_journals');
            $table->unsignedInteger('line_count');
            $table->jsonb('mapping');
            $table->unsignedBigInteger('imported_by');
            $table->timestamp('created_at');

            $table->unique(['company_id', 'source_key']);
            $table->unique(['company_id', 'journal_id']);
        });

        RetentionGuard::protect('gl_opening_balance_batches');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_opening_balance_batches');
    }
};
