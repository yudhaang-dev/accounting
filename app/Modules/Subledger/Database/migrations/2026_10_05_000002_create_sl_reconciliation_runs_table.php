<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A reconciliation of a control or cash account on a date (SL-FR-09): the balance of the ledger, the total of
| what the subledger holds for the account, and the difference with the journals that explain it. A difference
| can be waived with a reason, which blocks nothing but keeps who waived it and when.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_reconciliation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('kind', 8);
            CompanyKeys::reference($table, 'account_id', 'gl_accounts');
            $table->string('account_code', 32);
            $table->char('currency', 3);
            $table->date('as_of');
            $table->decimal('ledger_balance', 24, 6);
            $table->decimal('detail_total', 24, 6);
            $table->decimal('difference', 24, 6);
            $table->string('status', 10);
            $table->jsonb('causes');
            $table->string('waiver_reason', 500)->nullable();
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->unsignedBigInteger('run_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'as_of']);
            $table->index(['company_id', 'account_id', 'as_of']);
        });

        DB::statement("ALTER TABLE sl_reconciliation_runs ADD CONSTRAINT sl_reconciliation_runs_kind_check CHECK (kind IN ('control', 'cash'))");
        DB::statement("ALTER TABLE sl_reconciliation_runs ADD CONSTRAINT sl_reconciliation_runs_status_check CHECK (status IN ('balanced', 'difference', 'waived'))");
        DB::statement("ALTER TABLE sl_reconciliation_runs ADD CONSTRAINT sl_reconciliation_runs_waiver_check CHECK ((status = 'waived') = (waiver_reason IS NOT NULL AND waived_by IS NOT NULL AND waived_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sl_reconciliation_runs_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'difference'
                    OR NEW.status <> 'waived'
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.account_id IS DISTINCT FROM OLD.account_id
                    OR NEW.as_of IS DISTINCT FROM OLD.as_of
                    OR NEW.ledger_balance IS DISTINCT FROM OLD.ledger_balance
                    OR NEW.detail_total IS DISTINCT FROM OLD.detail_total
                    OR NEW.difference IS DISTINCT FROM OLD.difference
                    OR NEW.causes IS DISTINCT FROM OLD.causes THEN
                    RAISE EXCEPTION 'A reconciliation only changes by waiving its difference, once.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER sl_reconciliation_runs_only_waiver BEFORE UPDATE ON sl_reconciliation_runs FOR EACH ROW EXECUTE FUNCTION sl_reconciliation_runs_guard()');

        RetentionGuard::protect('sl_reconciliation_runs');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sl_reconciliation_runs_only_waiver ON sl_reconciliation_runs');
        DB::statement('DROP FUNCTION IF EXISTS sl_reconciliation_runs_guard()');
        Schema::dropIfExists('sl_reconciliation_runs');
    }
};
