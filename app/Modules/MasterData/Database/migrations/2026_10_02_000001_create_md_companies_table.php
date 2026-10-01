<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('md_companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->char('functional_currency', 3);
            $table->string('timezone', 64);
            $table->string('default_locale', 8);
            $table->string('status', 16)->default('setup');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('md_companies');
    }
};
