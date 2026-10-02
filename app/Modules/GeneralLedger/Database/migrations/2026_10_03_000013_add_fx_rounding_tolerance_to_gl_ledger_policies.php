<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Tolerance for the rounding difference of a foreign currency journal, in the functional currency
| (GL-FR-05). Zero by default: a difference then rejects the journal.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_ledger_policies', function (Blueprint $table) {
            $table->decimal('fx_rounding_tolerance', 24, 6)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('gl_ledger_policies', fn (Blueprint $table) => $table->dropColumn('fx_rounding_tolerance'));
    }
};
