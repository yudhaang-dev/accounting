<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Entries that come from a financial event (TE-FR-12). The client and its `source_system` come from the
| principal, never from the body; the source transaction ID and document number are what the source
| system calls the event, and the references are metadata kept as sent. Such an entry has no user as
| creator, and its first step in the trail is taken by the client.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->char('integration_client_id', 26)->nullable();
            $table->string('source_system', 64)->nullable();
            $table->string('source_transaction_id', 255)->nullable();
            $table->string('source_document_number', 255)->nullable();
            $table->jsonb('source_references')->nullable();

            $table->index(['company_id', 'source_system', 'source_transaction_id']);
        });

        Schema::table('te_entry_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->char('client_id', 26)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_entry_decisions', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });

        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'source_system', 'source_transaction_id']);
            $table->dropColumn(['source_references', 'source_document_number', 'source_transaction_id', 'source_system', 'integration_client_id']);
        });
    }
};
