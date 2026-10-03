<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The source key of an event is unique per company and source system (TE-FR-13). The database holds it, so
| that two events with the same key sent at the same moment end in one entry. The hash of the canonical
| payload is what a resend is compared with.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_entries', function (Blueprint $table) {
            $table->char('source_payload_hash', 64)->nullable();
        });

        DB::statement("create unique index te_entries_source_key_unique on te_entries (company_id, source_system, source_transaction_id) where source = 'integration'");
    }

    public function down(): void
    {
        DB::statement('drop index te_entries_source_key_unique');

        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropColumn('source_payload_hash');
        });
    }
};
