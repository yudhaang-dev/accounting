<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The other group company of an intragroup line (GL-FR-15). An opaque reference: the ledger neither
| resolves it nor relates it to another company's data (P-05). Without it a line is never eliminated.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_journal_lines', function (Blueprint $table) {
            $table->ulid('counterparty_company_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gl_journal_lines', function (Blueprint $table) {
            $table->dropColumn('counterparty_company_id');
        });
    }
};
