<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A cash account is a cash box or a bank account with one currency and one ledger account
| (SL-FR-03). Its transactions are recorded by the documents that move cash and never change
| afterwards, except that a user may mark them cleared or not (SL-FR-11): the database refuses
| any other update.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_cash_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('name');
            $table->string('kind', 8);
            $table->string('bank_name')->nullable();
            $table->string('account_number', 64)->nullable();
            $table->char('currency', 3);
            CompanyKeys::reference($table, 'account_id', 'gl_accounts');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            // One ledger account carries the balance of one cash account, so reconciliation never counts it twice.
            $table->unique(['company_id', 'account_id']);
        });

        DB::statement("ALTER TABLE sl_cash_accounts ADD CONSTRAINT sl_cash_accounts_kind_check CHECK (kind IN ('cash', 'bank'))");
        DB::statement("ALTER TABLE sl_cash_accounts ADD CONSTRAINT sl_cash_accounts_status_check CHECK (status IN ('active', 'inactive'))");

        Schema::create('sl_cash_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'cash_account_id', 'sl_cash_accounts');
            $table->string('direction', 3);
            $table->decimal('amount', 24, 6);
            $table->char('currency', 3);
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            // The document that moved the cash; the document tables arrive with M6-04.
            $table->ulid('document_id')->nullable();
            $table->date('cleared_on')->nullable();
            $table->unsignedBigInteger('cleared_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'cash_account_id', 'transaction_date'], 'sl_cash_transactions_account_date');
        });

        DB::statement("ALTER TABLE sl_cash_transactions ADD CONSTRAINT sl_cash_transactions_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement('ALTER TABLE sl_cash_transactions ADD CONSTRAINT sl_cash_transactions_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE sl_cash_transactions ADD CONSTRAINT sl_cash_transactions_cleared_check CHECK ((cleared_on IS NULL) = (cleared_by IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_cash_transactions_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.cash_account_id IS DISTINCT FROM OLD.cash_account_id
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id THEN
                    RAISE EXCEPTION 'Cash transactions only change their cleared status.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sl_cash_transactions_only_clearing BEFORE UPDATE ON sl_cash_transactions FOR EACH ROW EXECUTE FUNCTION sl_cash_transactions_guard()');

        foreach (['sl_cash_accounts', 'sl_cash_transactions'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sl_cash_transactions_only_clearing ON sl_cash_transactions');
        DB::statement('DROP FUNCTION IF EXISTS sl_cash_transactions_guard()');
        Schema::dropIfExists('sl_cash_transactions');
        Schema::dropIfExists('sl_cash_accounts');
    }
};
