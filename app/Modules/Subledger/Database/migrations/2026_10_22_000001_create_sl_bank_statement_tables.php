<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Bank reconciliation by statement import (SL-FR-13). A cash account keeps the mapping of the columns of
| its bank's CSV; each import is a statement with its closing balance and lines. A line is matched to at
| most one cash transaction and a cash transaction to at most one line; nothing else about a line changes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_bank_import_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'cash_account_id', 'sl_cash_accounts');
            $table->char('delimiter', 1)->default(',');
            $table->string('date_format', 16)->default('Y-m-d');
            $table->char('decimal_separator', 1)->default('.');
            $table->boolean('has_header')->default(true);
            // Positions of the columns, counted from 1.
            $table->unsignedSmallInteger('date_column');
            $table->unsignedSmallInteger('description_column')->nullable();
            $table->unsignedSmallInteger('reference_column')->nullable();
            // One signed amount column, or one column for money in and one for money out.
            $table->unsignedSmallInteger('amount_column')->nullable();
            $table->unsignedSmallInteger('money_in_column')->nullable();
            $table->unsignedSmallInteger('money_out_column')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'cash_account_id']);
        });

        DB::statement('ALTER TABLE sl_bank_import_mappings ADD CONSTRAINT sl_bank_import_mappings_amount_check CHECK ((amount_column IS NOT NULL AND money_in_column IS NULL AND money_out_column IS NULL) OR (amount_column IS NULL AND money_in_column IS NOT NULL AND money_out_column IS NOT NULL))');

        Schema::create('sl_bank_statements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'cash_account_id', 'sl_cash_accounts');
            $table->string('file_name')->nullable();
            // The same file is not imported twice into the same account.
            $table->char('file_hash', 64);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('closing_date');
            $table->decimal('closing_balance', 24, 6);
            $table->unsignedInteger('line_count');
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'cash_account_id', 'file_hash']);
            $table->index(['company_id', 'cash_account_id', 'closing_date'], 'sl_bank_statements_account_date');
        });

        Schema::create('sl_bank_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'statement_id', 'sl_bank_statements');
            CompanyKeys::reference($table, 'cash_account_id', 'sl_cash_accounts');
            $table->unsignedInteger('line_number');
            $table->date('transaction_date');
            $table->string('description', 500)->nullable();
            $table->string('reference')->nullable();
            $table->string('direction', 3);
            $table->decimal('amount', 24, 6);
            CompanyKeys::reference($table, 'cash_transaction_id', 'sl_cash_transactions', nullable: true);
            $table->timestamp('matched_at')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            // Whether the match, not a user before it, put the cleared mark on the transaction.
            $table->boolean('cleared_by_match')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'statement_id', 'line_number']);
            $table->unique(['company_id', 'cash_transaction_id']);
            $table->index(['company_id', 'cash_account_id', 'transaction_date'], 'sl_bank_statement_lines_account_date');
        });

        DB::statement("ALTER TABLE sl_bank_statement_lines ADD CONSTRAINT sl_bank_statement_lines_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement('ALTER TABLE sl_bank_statement_lines ADD CONSTRAINT sl_bank_statement_lines_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE sl_bank_statement_lines ADD CONSTRAINT sl_bank_statement_lines_match_check CHECK ((cash_transaction_id IS NULL) = (matched_at IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_bank_statement_lines_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.statement_id IS DISTINCT FROM OLD.statement_id
                    OR NEW.cash_account_id IS DISTINCT FROM OLD.cash_account_id
                    OR NEW.line_number IS DISTINCT FROM OLD.line_number
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.amount IS DISTINCT FROM OLD.amount THEN
                    RAISE EXCEPTION 'Bank statement lines only change their match.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sl_bank_statement_lines_only_matching BEFORE UPDATE ON sl_bank_statement_lines FOR EACH ROW EXECUTE FUNCTION sl_bank_statement_lines_guard()');

        foreach (['sl_bank_import_mappings', 'sl_bank_statements', 'sl_bank_statement_lines'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sl_bank_statement_lines_only_matching ON sl_bank_statement_lines');
        DB::statement('DROP FUNCTION IF EXISTS sl_bank_statement_lines_guard()');
        Schema::dropIfExists('sl_bank_statement_lines');
        Schema::dropIfExists('sl_bank_statements');
        Schema::dropIfExists('sl_bank_import_mappings');
    }
};
