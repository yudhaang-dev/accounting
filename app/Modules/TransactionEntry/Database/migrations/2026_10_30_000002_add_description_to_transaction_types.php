<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A transaction type may say when it is used (TE-FR-01). The accountant writes it; the catalog an operator
| picks a type from shows it, so the operator knows which type fits the event without knowing the books.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_transaction_types', function (Blueprint $table) {
            $table->string('description', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_transaction_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
