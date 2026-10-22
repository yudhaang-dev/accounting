<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Webhook endpoint of an integration client (API-TR-25). The secret signs what is sent, so it has to be
| readable by the server again; it is stored encrypted and shown to the user only when it is issued.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ia_integration_clients', function (Blueprint $table) {
            $table->string('webhook_url', 500)->nullable()->after('transaction_type_keys');
            $table->text('webhook_secret')->nullable()->after('webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('ia_integration_clients', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret']);
        });
    }
};
