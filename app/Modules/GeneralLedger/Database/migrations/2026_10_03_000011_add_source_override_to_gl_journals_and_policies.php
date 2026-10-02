<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Control account override (GL-FR-07). The ledger policy names the permission that may post to an
| account from a source it does not accept; no permission means no override, the default. A journal
| posted that way carries the reason it was given (set only then), so the mark is part of the
| immutable journal.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_ledger_policies', function (Blueprint $table) {
            $table->string('source_override_permission', 64)->nullable();
        });

        Schema::table('gl_journals', function (Blueprint $table) {
            $table->string('source_override_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gl_journals', fn (Blueprint $table) => $table->dropColumn('source_override_reason'));
        Schema::table('gl_ledger_policies', fn (Blueprint $table) => $table->dropColumn('source_override_permission'));
    }
};
