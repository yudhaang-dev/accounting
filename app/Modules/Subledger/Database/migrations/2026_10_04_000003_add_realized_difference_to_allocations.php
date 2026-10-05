<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Open items of one party recorded at different rates settle each other at different functional values
| (SL-FR-06). An allocation keeps the functional value of each side, so cancelling it gives each open
| item back what it lost, and the difference between them, which the journal booked to the realized
| exchange accounts. `functional_amount` stays the part of the debit side; positive `realized_difference`
| is a gain. An allocation asked for afterwards books its difference in a journal of its own, and
| cancelling it books the opposite one.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sl_allocations', function (Blueprint $table) {
            $table->decimal('credit_functional_amount', 24, 6)->nullable();
            $table->decimal('realized_difference', 24, 6)->default(0);
            CompanyKeys::reference($table, 'journal_id', 'gl_journals', nullable: true);
            CompanyKeys::reference($table, 'cancel_journal_id', 'gl_journals', nullable: true);
        });

        DB::statement('UPDATE sl_allocations SET credit_functional_amount = functional_amount');
        DB::statement('ALTER TABLE sl_allocations ALTER COLUMN credit_functional_amount SET NOT NULL');
        DB::statement('ALTER TABLE sl_allocations ADD CONSTRAINT sl_allocations_credit_part_check CHECK (credit_functional_amount > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_allocations_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'cancelled'
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.debit_item_id IS DISTINCT FROM OLD.debit_item_id
                    OR NEW.credit_item_id IS DISTINCT FROM OLD.credit_item_id
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id
                    OR NEW.journal_id IS DISTINCT FROM OLD.journal_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.functional_amount IS DISTINCT FROM OLD.functional_amount
                    OR NEW.credit_functional_amount IS DISTINCT FROM OLD.credit_functional_amount
                    OR NEW.realized_difference IS DISTINCT FROM OLD.realized_difference
                    OR NEW.allocation_date IS DISTINCT FROM OLD.allocation_date THEN
                    RAISE EXCEPTION 'An allocation only changes by being cancelled, once.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // A cash transaction of a transfer between cash accounts has no document; the journal it came from tells where it is from.
        Schema::table('sl_cash_transactions', function (Blueprint $table) {
            CompanyKeys::reference($table, 'journal_id', 'gl_journals', nullable: true);
        });

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
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id
                    OR NEW.journal_id IS DISTINCT FROM OLD.journal_id THEN
                    RAISE EXCEPTION 'Cash transactions only change their cleared status.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        Schema::table('sl_cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'journal_id']);
            $table->dropColumn('journal_id');
        });

        Schema::table('sl_allocations', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'journal_id']);
            $table->dropForeign(['company_id', 'cancel_journal_id']);
            $table->dropColumn(['credit_functional_amount', 'realized_difference', 'journal_id', 'cancel_journal_id']);
        });
    }
};
