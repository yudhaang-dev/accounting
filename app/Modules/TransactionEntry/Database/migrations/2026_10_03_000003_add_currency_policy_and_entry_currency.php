<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Currencies and rates of entries (TE-FR-06). A version names the currencies it accepts, the rate
| type to look rates up in and whether an entry may bring its own rate. A structured entry has one
| currency and optionally the rate it brings; a free-lines entry keeps both on each line.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->jsonb('allowed_currencies')->nullable();
            $table->string('rate_type_key', 32)->nullable();
            $table->boolean('allow_rate_input')->default(false);
        });

        Schema::table('te_entries', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->string('exchange_rate', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });

        Schema::table('te_transaction_type_versions', function (Blueprint $table) {
            $table->dropColumn(['allowed_currencies', 'rate_type_key', 'allow_rate_input']);
        });
    }
};
