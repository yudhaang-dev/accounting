<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The open items of the conversion date come with the opening balance journal (SL-FR-10): one journal, many
| documents, so the journal of a document is unique only for the documents entries made. Such a document
| names the control account it stands on, since its journal holds the lines of every control account.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sl_documents', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false);
            CompanyKeys::reference($table, 'control_account_id', 'gl_accounts', nullable: true);
            $table->dropUnique(['company_id', 'journal_id']);
        });

        DB::statement('CREATE UNIQUE INDEX sl_documents_company_id_journal_id_unique ON sl_documents (company_id, journal_id) WHERE NOT is_opening');
        DB::statement('ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_opening_check CHECK (NOT is_opening OR (control_account_id IS NOT NULL AND entry_id IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_opening_check');
        DB::statement('DROP INDEX sl_documents_company_id_journal_id_unique');

        Schema::table('sl_documents', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'control_account_id']);
            $table->dropColumn(['is_opening', 'control_account_id']);
            $table->unique(['company_id', 'journal_id']);
        });
    }
};
