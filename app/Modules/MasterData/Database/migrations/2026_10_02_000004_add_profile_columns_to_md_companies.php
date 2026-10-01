<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('md_companies', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('tax_id', 64)->nullable()->after('legal_name');
            $table->unsignedInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('md_companies', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'tax_id', 'lock_version']);
        });
    }
};
