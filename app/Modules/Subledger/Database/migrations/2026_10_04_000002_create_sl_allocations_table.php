<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| An allocation settles an amount of a debit side open item against a credit side one of the same party,
| control account and currency (SL-FR-05). It is never deleted: cancelling it marks it, with who, when and
| why, and gives the amounts back to the open items. The database refuses any other change.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_kind_check');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_kind_check CHECK (kind IN ('invoice', 'credit_note', 'payment', 'advance', 'write_off'))");

        Schema::create('sl_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'debit_item_id', 'sl_open_items');
            CompanyKeys::reference($table, 'credit_item_id', 'sl_open_items');
            // The document whose posting made the allocation; empty for one asked for afterwards.
            CompanyKeys::reference($table, 'document_id', 'sl_documents', nullable: true);
            $table->decimal('amount', 24, 6);
            $table->decimal('functional_amount', 24, 6);
            $table->date('allocation_date');
            $table->string('status', 10)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->date('cancelled_on')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'debit_item_id', 'status']);
            $table->index(['company_id', 'credit_item_id', 'status']);
            $table->index(['company_id', 'document_id']);
        });

        DB::statement("ALTER TABLE sl_allocations ADD CONSTRAINT sl_allocations_status_check CHECK (status IN ('active', 'cancelled'))");
        DB::statement('ALTER TABLE sl_allocations ADD CONSTRAINT sl_allocations_amount_check CHECK (amount > 0 AND functional_amount > 0 AND debit_item_id <> credit_item_id)');
        DB::statement("ALTER TABLE sl_allocations ADD CONSTRAINT sl_allocations_cancel_check CHECK ((status = 'cancelled') = (cancelled_on IS NOT NULL AND cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_allocations_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'cancelled'
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.debit_item_id IS DISTINCT FROM OLD.debit_item_id
                    OR NEW.credit_item_id IS DISTINCT FROM OLD.credit_item_id
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.functional_amount IS DISTINCT FROM OLD.functional_amount
                    OR NEW.allocation_date IS DISTINCT FROM OLD.allocation_date THEN
                    RAISE EXCEPTION 'An allocation only changes by being cancelled, once.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sl_allocations_only_cancel BEFORE UPDATE ON sl_allocations FOR EACH ROW EXECUTE FUNCTION sl_allocations_guard()');

        RetentionGuard::protect('sl_allocations');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sl_allocations_only_cancel ON sl_allocations');
        DB::statement('DROP FUNCTION IF EXISTS sl_allocations_guard()');
        Schema::dropIfExists('sl_allocations');
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_kind_check');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_kind_check CHECK (kind IN ('invoice', 'credit_note', 'payment', 'advance'))");
    }
};
