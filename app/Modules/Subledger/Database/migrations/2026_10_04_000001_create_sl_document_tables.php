<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A document is what an entry with a subledger route records about a party: one document per journal
| (SL-FR-04). It never changes. Its open item is what is still to be settled, in the transaction currency
| and in the functional one; only the remaining amounts and the status change afterwards, which
| allocations do (SL-FR-05). `side` is the side of the control account line the document posted, so the
| open items of a control account add up to its balance (SL-FR-09).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('kind', 16);
            $table->string('control_role', 32);
            CompanyKeys::reference($table, 'party_id', 'sl_parties');
            CompanyKeys::reference($table, 'cash_account_id', 'sl_cash_accounts', nullable: true);
            CompanyKeys::reference($table, 'journal_id', 'gl_journals');
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->date('due_date');
            $table->date('accounting_date');
            $table->char('currency', 3);
            $table->decimal('amount', 24, 6);
            $table->decimal('functional_amount', 24, 6);
            $table->decimal('exchange_rate', 24, 12);
            $table->string('description', 500);
            // The entry that made the document, as the journal knows it; a second posting of the entry finds its document by it.
            $table->ulid('entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'journal_id']);
            $table->unique(['company_id', 'entry_id']);
            $table->index(['company_id', 'party_id', 'document_date']);
        });

        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_kind_check CHECK (kind IN ('invoice', 'credit_note', 'payment', 'advance'))");
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_role_check CHECK (control_role IN ('receivables_control', 'payables_control'))");
        DB::statement('ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_amount_check CHECK (amount > 0 AND functional_amount > 0 AND exchange_rate > 0)');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_cash_check CHECK ((kind IN ('payment', 'advance')) = (cash_account_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_documents_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Documents never change; reverse them instead.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sl_documents_immutable BEFORE UPDATE ON sl_documents FOR EACH ROW EXECUTE FUNCTION sl_documents_guard()');

        Schema::create('sl_open_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'document_id', 'sl_documents');
            CompanyKeys::reference($table, 'party_id', 'sl_parties');
            $table->string('control_role', 32);
            $table->string('kind', 16);
            $table->string('side', 6);
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->decimal('original_amount', 24, 6);
            $table->decimal('remaining_amount', 24, 6);
            $table->decimal('original_functional', 24, 6);
            $table->decimal('remaining_functional', 24, 6);
            $table->decimal('exchange_rate', 24, 12);
            $table->string('status', 8)->default('open');
            $table->timestamps();

            $table->unique(['company_id', 'document_id']);
            $table->index(['company_id', 'party_id', 'status']);
            $table->index(['company_id', 'control_role', 'status']);
        });

        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_side_check CHECK (side IN ('debit', 'credit'))");
        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_status_check CHECK (status IN ('open', 'settled'))");
        DB::statement('ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_amount_check CHECK (original_amount > 0 AND original_functional > 0 AND remaining_amount >= 0 AND remaining_amount <= original_amount AND remaining_functional >= 0)');
        DB::statement("ALTER TABLE sl_open_items ADD CONSTRAINT sl_open_items_settled_check CHECK ((status = 'settled') = (remaining_amount = 0))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_open_items_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id
                    OR NEW.party_id IS DISTINCT FROM OLD.party_id
                    OR NEW.control_role IS DISTINCT FROM OLD.control_role
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.side IS DISTINCT FROM OLD.side
                    OR NEW.document_number IS DISTINCT FROM OLD.document_number
                    OR NEW.document_date IS DISTINCT FROM OLD.document_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.original_amount IS DISTINCT FROM OLD.original_amount
                    OR NEW.original_functional IS DISTINCT FROM OLD.original_functional
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate THEN
                    RAISE EXCEPTION 'An open item only changes what remains of it.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sl_open_items_only_remaining BEFORE UPDATE ON sl_open_items FOR EACH ROW EXECUTE FUNCTION sl_open_items_guard()');

        // The cash transaction table came first; its document column gets its key now.
        Schema::table('sl_cash_transactions', function (Blueprint $table) {
            $table->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('sl_documents');
        });

        foreach (['sl_documents', 'sl_open_items'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::table('sl_cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'document_id']);
        });

        DB::statement('DROP TRIGGER IF EXISTS sl_open_items_only_remaining ON sl_open_items');
        DB::statement('DROP FUNCTION IF EXISTS sl_open_items_guard()');
        Schema::dropIfExists('sl_open_items');
        DB::statement('DROP TRIGGER IF EXISTS sl_documents_immutable ON sl_documents');
        DB::statement('DROP FUNCTION IF EXISTS sl_documents_guard()');
        Schema::dropIfExists('sl_documents');
    }
};
