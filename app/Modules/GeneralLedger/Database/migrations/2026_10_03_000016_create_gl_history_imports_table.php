<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| History between the ledger start date and the cutover date (GL-FR-11). One import per opening
| balance batch, in the granularity the source could give; the journals it posted are marked by
| their transaction type key `system.history_import`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_history_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'batch_id', 'gl_opening_balance_batches');
            $table->string('source_key', 100);
            $table->string('payload_hash', 64);
            $table->string('granularity', 16);
            $table->unsignedInteger('journal_count');
            $table->jsonb('mapping');
            $table->unsignedBigInteger('imported_by');
            $table->timestamp('created_at');

            $table->unique(['company_id', 'batch_id']);
            $table->unique(['company_id', 'source_key']);
        });

        RetentionGuard::protect('gl_history_imports');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_history_imports');
    }
};
