<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Outbox: facts that must reach something outside the transaction that made them (API-TR-25). A row is
| written in the transaction of the fact, so it exists exactly when the fact does, and is relayed after
| commit. `available_at` is when the next attempt of the relay may start.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pf_outbox', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id');
            $table->string('topic', 64);
            $table->string('subject_id', 26)->nullable();
            $table->jsonb('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at', 6);
            $table->timestampTz('dispatched_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['dispatched_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_outbox');
    }
};
