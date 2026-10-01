<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Posted journals, their lines and the aggregated account balances (GL-FR-05, GL-FR-06, P-03).
| Journals and lines are append-only: UPDATE and DELETE are rejected by trigger, and a journal must
| have at least two lines that balance exactly (checked at commit). Lines carry the transaction
| currency columns of ADR-0008 and the dimension set of ADR-0007 from the start.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_journals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('number', 64);
            $table->date('accounting_date');
            CompanyKeys::reference($table, 'period_id', 'gl_periods');
            $table->date('document_date')->nullable();
            $table->string('description', 500);
            $table->string('source', 16);
            // Opaque metadata of the caller; the ledger never interprets it.
            $table->string('transaction_type_key', 64)->nullable();
            $table->unsignedSmallInteger('transaction_type_version')->nullable();
            $table->jsonb('source_references')->nullable();
            CompanyKeys::reference($table, 'reverses_journal_id', 'gl_journals', nullable: true);
            // Idempotency key supplied by the caller: one journal per entry (GL-FR-05).
            $table->string('entry_id', 64)->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestampTz('posted_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'entry_id']);
            $table->unique(['company_id', 'reverses_journal_id']);
            $table->index(['company_id', 'accounting_date']);
        });

        Schema::create('gl_journal_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'journal_id', 'gl_journals');
            $table->unsignedSmallInteger('line_number');
            CompanyKeys::reference($table, 'account_id', 'gl_accounts');
            CompanyKeys::reference($table, 'period_id', 'gl_periods');
            $table->date('accounting_date');
            $table->decimal('debit', 24, 6);
            $table->decimal('credit', 24, 6);
            $table->char('currency', 3);
            $table->decimal('transaction_amount', 24, 6);
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->ulid('rate_type_id')->nullable();
            $table->date('rate_date')->nullable();
            CompanyKeys::reference($table, 'dimension_set_id', 'md_dimension_sets');
            $table->string('description', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['journal_id', 'line_number']);
            $table->index(['company_id', 'account_id', 'accounting_date']);
        });

        Schema::create('gl_account_balances', function (Blueprint $table) {
            $table->ulid('company_id');
            $table->ulid('account_id');
            $table->ulid('period_id');
            $table->ulid('dimension_set_id');
            $table->char('currency', 3);
            $table->decimal('debit', 24, 6)->default(0);
            $table->decimal('credit', 24, 6)->default(0);
            $table->decimal('transaction_debit', 24, 6)->default(0);
            $table->decimal('transaction_credit', 24, 6)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['company_id', 'account_id', 'period_id', 'dimension_set_id', 'currency']);
            $table->foreign('company_id')->references('id')->on(CompanyKeys::COMPANIES_TABLE);
            $table->foreign(['company_id', 'account_id'])->references(['company_id', 'id'])->on('gl_accounts');
            $table->foreign(['company_id', 'period_id'])->references(['company_id', 'id'])->on('gl_periods');
            $table->foreign(['company_id', 'dimension_set_id'])->references(['company_id', 'id'])->on('md_dimension_sets');
        });

        DB::statement("ALTER TABLE gl_journals ADD CONSTRAINT gl_journals_source_check CHECK (source IN ('entry', 'subledger', 'system'))");
        DB::statement('ALTER TABLE gl_journal_lines ADD CONSTRAINT gl_journal_lines_one_side_check CHECK ((debit > 0 AND credit = 0) OR (debit = 0 AND credit > 0))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gl_reject_journal_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Posted journals are immutable (GL-FR-06): % on % is not allowed; post a reversal instead', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = '55000';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gl_check_journal_balance() RETURNS trigger AS $$
            DECLARE
                difference numeric;
                line_count integer;
            BEGIN
                SELECT coalesce(sum(debit - credit), 0), count(*) INTO difference, line_count
                FROM gl_journal_lines WHERE journal_id = NEW.journal_id;

                IF line_count < 2 THEN
                    RAISE EXCEPTION 'Journal % needs at least two lines', NEW.journal_id USING ERRCODE = '23514';
                END IF;

                IF difference <> 0 THEN
                    RAISE EXCEPTION 'Journal % is not balanced (debit minus credit is %)', NEW.journal_id, difference USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (['gl_journals', 'gl_journal_lines'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION gl_reject_journal_change()");
        }

        DB::statement('CREATE CONSTRAINT TRIGGER gl_journal_lines_balanced AFTER INSERT ON gl_journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION gl_check_journal_balance()');

        foreach (['gl_journals', 'gl_journal_lines', 'gl_account_balances'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_account_balances');
        Schema::dropIfExists('gl_journal_lines');
        Schema::dropIfExists('gl_journals');
        DB::statement('DROP FUNCTION IF EXISTS gl_check_journal_balance()');
        DB::statement('DROP FUNCTION IF EXISTS gl_reject_journal_change()');
    }
};
