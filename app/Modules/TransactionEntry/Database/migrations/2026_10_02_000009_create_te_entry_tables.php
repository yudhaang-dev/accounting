<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Transaction types, their versions, entries and the approval trail (TE-FR-01, TE-FR-03, TE-FR-07).
| The journal of a posted entry is referenced by plain ID only: the ledger is another module (ADR-0002).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('te_transaction_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('key', 64);
            $table->string('label');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('te_transaction_type_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'transaction_type_id', 'te_transaction_types');
            $table->unsignedSmallInteger('version');
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['transaction_type_id', 'version']);
        });

        Schema::create('te_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'transaction_type_id', 'te_transaction_types');
            $table->string('transaction_type_key', 64);
            $table->unsignedSmallInteger('transaction_type_version');
            $table->string('number', 64);
            $table->string('status', 16)->default('draft');
            $table->string('source', 16)->default('manual');
            $table->date('accounting_date');
            $table->date('document_date')->nullable();
            $table->string('description', 500);
            $table->jsonb('lines');
            $table->unsignedBigInteger('created_by');
            $table->ulid('journal_id')->nullable();
            $table->string('journal_number', 64)->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'accounting_date']);
        });

        Schema::create('te_entry_decisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'entry_id', 'te_entries');
            $table->string('action', 16);
            $table->unsignedBigInteger('actor_id');
            $table->string('reason', 500)->nullable();
            $table->timestampTz('created_at');

            $table->index(['entry_id', 'created_at']);
        });

        DB::statement("ALTER TABLE te_transaction_type_versions ADD CONSTRAINT te_transaction_type_versions_mode_check CHECK (mode IN ('structured', 'free_lines'))");
        DB::statement("ALTER TABLE te_transaction_type_versions ADD CONSTRAINT te_transaction_type_versions_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE te_entries ADD CONSTRAINT te_entries_status_check CHECK (status IN ('draft', 'submitted', 'approved', 'posted', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE te_entries ADD CONSTRAINT te_entries_source_check CHECK (source IN ('manual', 'integration', 'recurring'))");
        DB::statement("ALTER TABLE te_entries ADD CONSTRAINT te_entries_posted_check CHECK ((status = 'posted') = (journal_id IS NOT NULL))");

        foreach (['te_transaction_types', 'te_transaction_type_versions', 'te_entries', 'te_entry_decisions'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('te_entry_decisions');
        Schema::dropIfExists('te_entries');
        Schema::dropIfExists('te_transaction_type_versions');
        Schema::dropIfExists('te_transaction_types');
    }
};
