<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The subledger route of a `structured` version (TE-FR-02, TE-FR-10): which kind of document an entry of
| the version records, for which control account role, and which fields hold the party, the cash account
| and the document data. Null for a version whose entries go straight to the ledger.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->jsonb('subledger_route')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->dropColumn('subledger_route');
        });
    }
};
