<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pf_idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('principal');
            $table->string('scope');
            $table->string('key');
            $table->char('fingerprint', 64);
            $table->string('state', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at')->index();

            $table->unique(['principal', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_idempotency_keys');
    }
};
