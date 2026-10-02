<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Roles are edited through the API, so they take part in optimistic concurrency (API-TR-14).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ia_roles', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('ia_roles', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
