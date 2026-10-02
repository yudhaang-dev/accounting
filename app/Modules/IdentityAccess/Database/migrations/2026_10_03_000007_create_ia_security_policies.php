<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Security policy of a company (IA-FR-05): permissions that demand 2FA, idle session limit and the
| IP allowlist of integration clients. Versioned and append-only (P-04): a change adds a row, the
| highest version is current. No row means no requirements.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_security_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->unsignedInteger('version');
            $table->jsonb('two_factor_permissions');
            $table->unsignedSmallInteger('idle_timeout_minutes')->nullable();
            $table->jsonb('client_ip_allowlist');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at');

            $table->unique(['company_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_security_policies');
    }
};
