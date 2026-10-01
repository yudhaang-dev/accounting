<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pf_number_series', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id')->index();
            $table->string('key');
            $table->string('pattern');
            $table->unsignedSmallInteger('padding')->default(1);
            $table->string('reset_period', 16);
            $table->timestampsTz();

            $table->unique(['company_id', 'key']);
        });

        // One counter row per series and reset bucket (for example "2026-10"). Rows are locked
        // FOR UPDATE inside the caller's transaction, which is what makes numbers gapless.
        Schema::create('pf_number_counters', function (Blueprint $table) {
            $table->foreignUlid('series_id')->constrained('pf_number_series');
            $table->string('bucket', 16);
            $table->unsignedBigInteger('last_value')->default(0);

            $table->primary(['series_id', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_number_counters');
        Schema::dropIfExists('pf_number_series');
    }
};
