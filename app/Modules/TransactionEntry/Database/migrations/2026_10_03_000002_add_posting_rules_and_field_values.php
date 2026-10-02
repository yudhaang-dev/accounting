<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The posting rules of a `structured` version (TE-FR-05) and the field values of an entry on such a
| version. Both are null for `free_lines`, where the entry carries its own lines.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->jsonb('posting_rules')->nullable();
        });

        Schema::table('te_entries', function (Blueprint $table) {
            $table->jsonb('field_values')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropColumn('field_values');
        });

        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->dropColumn('posting_rules');
        });
    }
};
