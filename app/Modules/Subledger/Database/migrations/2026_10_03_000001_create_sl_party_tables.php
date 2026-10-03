<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Parties are the customers and suppliers behind the receivable and payable control accounts
| (SL-FR-01). A party is never deleted (X-FR-05); it is deactivated. External references are
| unique per (company, source_system): the same reference from the same source always resolves
| to the same party, and from another source it is a different one (SL-AC-08).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_parties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('name');
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->string('tax_id', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        DB::statement('ALTER TABLE sl_parties ADD CONSTRAINT sl_parties_role_check CHECK (is_customer OR is_supplier)');
        DB::statement("ALTER TABLE sl_parties ADD CONSTRAINT sl_parties_status_check CHECK (status IN ('active', 'inactive'))");

        Schema::create('sl_party_external_refs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'party_id', 'sl_parties');
            $table->string('source_system', 64);
            $table->string('external_ref', 128);
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'source_system', 'external_ref'], 'sl_party_external_refs_unique');
            $table->index(['company_id', 'party_id']);
        });

        foreach (['sl_parties', 'sl_party_external_refs'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sl_party_external_refs');
        Schema::dropIfExists('sl_parties');
    }
};
