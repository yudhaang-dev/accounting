<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Integration clients (IA-FR-06, API-TR-24): the machine principal behind a Passport client. It is
| bound to one company and one source_system, and limited by scopes and an allowlist of transaction
| type keys. The secret lives only in Passport's client table, hashed; it is never stored here.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_integration_clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->uuid('oauth_client_id')->unique();
            $table->string('name');
            $table->string('source_system', 64);
            $table->jsonb('scopes');
            $table->jsonb('transaction_type_keys');
            $table->string('status', 16)->default('active');
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'source_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_integration_clients');
    }
};
