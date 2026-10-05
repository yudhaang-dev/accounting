<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Reversing a document (SL-FR-12) never changes the document, which is immutable: the reversal is a record of
| its own, with the journal that undid the posting. The open item of a reversed document is left with nothing
| to settle and the status `reversed`, so the open items of a control account keep adding up to its balance.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_document_reversals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'document_id', 'sl_documents');
            CompanyKeys::reference($table, 'journal_id', 'gl_journals');
            $table->date('reversal_date');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_id']);
            $table->unique(['company_id', 'journal_id']);
        });

        DB::statement('ALTER TABLE sl_open_items DROP CONSTRAINT sl_open_items_status_check');
        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_status_check CHECK (status IN ('open', 'settled', 'reversed'))");
        DB::statement('ALTER TABLE sl_open_items DROP CONSTRAINT sl_open_items_settled_check');
        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_settled_check CHECK ((status = 'open') = (remaining_amount > 0))");

        RetentionGuard::protect('sl_document_reversals');
    }

    public function down(): void
    {
        Schema::dropIfExists('sl_document_reversals');

        DB::statement('ALTER TABLE sl_open_items DROP CONSTRAINT sl_open_items_settled_check');
        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_settled_check CHECK ((status = 'settled') = (remaining_amount = 0))");
        DB::statement('ALTER TABLE sl_open_items DROP CONSTRAINT sl_open_items_status_check');
        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_status_check CHECK (status IN ('open', 'settled'))");
    }
};
