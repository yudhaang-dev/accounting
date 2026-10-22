<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The group company a party stands for, when it is one (GL-FR-15). An opaque reference, like the one on a
| journal line: the subledger neither resolves it nor reads that company's data.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sl_parties', function (Blueprint $table) {
            $table->ulid('counterparty_company_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sl_parties', function (Blueprint $table) {
            $table->dropColumn('counterparty_company_id');
        });
    }
};
