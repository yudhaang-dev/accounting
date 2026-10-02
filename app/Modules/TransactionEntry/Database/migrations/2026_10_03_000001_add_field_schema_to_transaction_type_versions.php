<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The field schema of a `structured` version (TE-FR-04): an ordered list of field definitions.
| Null for `free_lines` versions, whose lines are not named fields.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->jsonb('fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->dropColumn('fields');
        });
    }
};
