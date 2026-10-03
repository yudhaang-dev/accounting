<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The point where an entry gets its number is a policy of its transaction type (TE-FR-16): when the draft
| is created, or only when it is submitted. Until then an entry of the second kind has no number.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_transaction_types', function (Blueprint $table) {
            $table->string('number_policy', 16)->default('on_create');
        });

        DB::statement("ALTER TABLE te_transaction_types ADD CONSTRAINT te_transaction_types_number_policy_check CHECK (number_policy IN ('on_create', 'on_submit'))");
        DB::statement('ALTER TABLE te_entries ALTER COLUMN number DROP NOT NULL');
        DB::statement("ALTER TABLE te_entries ADD CONSTRAINT te_entries_number_check CHECK (number IS NOT NULL OR status IN ('draft', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE te_entries DROP CONSTRAINT te_entries_number_check');
        DB::statement('ALTER TABLE te_entries ALTER COLUMN number SET NOT NULL');
        DB::statement('ALTER TABLE te_transaction_types DROP CONSTRAINT te_transaction_types_number_policy_check');

        Schema::table('te_transaction_types', function (Blueprint $table) {
            $table->dropColumn('number_policy');
        });
    }
};
