<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Which account plays a ledger role (GL-FR-10). Versioned like the policies: a change adds a row
| with the next version and the latest one is current; earlier versions stay for the postings that
| used them (P-04). No row means the role is not mapped.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_account_role_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('account_role', 32);
            $table->unsignedInteger('version');
            CompanyKeys::reference($table, 'account_id', 'gl_accounts');
            $table->date('effective_date');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');

            $table->unique(['company_id', 'account_role', 'version']);
        });

        RetentionGuard::protect('gl_account_role_mappings');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_account_role_mappings');
    }
};
