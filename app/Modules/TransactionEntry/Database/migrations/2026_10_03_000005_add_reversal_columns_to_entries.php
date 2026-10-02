<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Reversal, replacement and scheduled reversal of entries (TE-FR-11). A `system.reversal` entry names the
| journal it reverses by plain ID (the ledger is another module, ADR-0002) and carries the reason. An entry
| may name the entry it replaces and a date on which the system reverses it once it is posted; the outcome
| of that reversal is kept on the entry so that a failure stays visible.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_entries', function (Blueprint $table) {
            $table->ulid('reverses_journal_id')->nullable();
            $table->string('reason', 500)->nullable();
            CompanyKeys::reference($table, 'replaces_entry_id', 'te_entries', nullable: true);
            $table->date('auto_reverse_on')->nullable();
            $table->string('auto_reversal_status', 16)->nullable();
            $table->string('auto_reversal_error', 500)->nullable();
            CompanyKeys::reference($table, 'auto_reversal_entry_id', 'te_entries', nullable: true);

            $table->index(['auto_reversal_status', 'auto_reverse_on']);
        });

        DB::statement("ALTER TABLE te_entries ADD CONSTRAINT te_entries_auto_reversal_status_check CHECK (auto_reversal_status IN ('pending', 'done', 'failed'))");
        // A journal has at most one reversal under way or done; a cancelled or rejected request frees it again.
        DB::statement("CREATE UNIQUE INDEX te_entries_one_reversal_per_journal ON te_entries (company_id, reverses_journal_id) WHERE reverses_journal_id IS NOT NULL AND status IN ('draft', 'submitted', 'approved', 'posted')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS te_entries_one_reversal_per_journal');

        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropColumn(['auto_reversal_entry_id', 'auto_reversal_error', 'auto_reversal_status', 'auto_reverse_on', 'replaces_entry_id', 'reason', 'reverses_journal_id']);
        });
    }
};
