<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A definition says whether closing a period or a fiscal year publishes it as a snapshot (RP-FR-14).
| Both start off, so a company that already has definitions keeps publishing by hand.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rp_report_definitions', function (Blueprint $table) {
            $table->boolean('publish_on_period_close')->default(false);
            $table->boolean('publish_on_year_close')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('rp_report_definitions', function (Blueprint $table) {
            $table->dropColumn(['publish_on_period_close', 'publish_on_year_close']);
        });
    }
};
