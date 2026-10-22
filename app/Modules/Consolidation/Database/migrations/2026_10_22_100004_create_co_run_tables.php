<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Consolidation runs (CO-FR-09, X-FR-03). A run is one reading of the packages of the perimeter for a range,
| through one version of the account mapping, kept as it was computed: the entities that took part with the
| status and coverage of their packages, and every account balance of every entity with the group account it
| was mapped to, or none. Group lines are sums over those rows, so each is traceable to the accounts and
| entities behind it. Changing the perimeter, the mapping or a ledger never alters a run; it makes a new one.
| The rows are append-only: UPDATE and DELETE are refused by trigger.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->date('from_date');
            $table->date('to_date');
            // What the result is: only the roll-up of the management stage exists so far (CO-FR-04).
            $table->string('label', 32);
            $table->string('status', 16);
            $table->char('presentation_currency', 3);
            // The version of the account mapping in force at the cutoff date; null when there was none.
            $table->unsignedInteger('mapping_version')->nullable();
            $table->jsonb('perimeter');
            $table->string('package_policy', 16);
            $table->string('coverage_status', 16);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['group_id', 'created_at']);
        });

        DB::statement("ALTER TABLE co_runs ADD CONSTRAINT co_runs_status_check CHECK (status IN ('complete', 'draft'))");

        Schema::create('co_run_entities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('co_runs');
            $table->foreignUlid('company_id')->constrained('md_companies');
            $table->string('company_name');
            $table->char('functional_currency', 3);
            $table->string('book_status', 16);
            $table->date('coverage_from')->nullable();
            $table->date('coverage_to')->nullable();
            $table->string('coverage_granularity', 16);
            $table->string('coverage_status', 16);
            $table->boolean('balanced');
            // Where the package came from: the report, its parameters, the opening batch and the mapping versions it read.
            $table->jsonb('source');

            $table->unique(['run_id', 'company_id']);
        });

        Schema::create('co_run_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('co_runs');
            $table->foreignUlid('company_id')->constrained('md_companies');
            $table->ulid('account_id');
            $table->string('account_code', 64);
            $table->string('account_name');
            $table->string('account_class', 16);
            // The group account it was mapped to, as it was named then; all null for an account without a mapping.
            $table->ulid('group_account_id')->nullable();
            $table->string('group_account_code', 32)->nullable();
            $table->string('group_account_name')->nullable();
            $table->string('group_account_class', 16)->nullable();
            $table->decimal('opening_debit', 24, 6);
            $table->decimal('opening_credit', 24, 6);
            $table->decimal('debit', 24, 6);
            $table->decimal('credit', 24, 6);
            $table->decimal('closing_debit', 24, 6);
            $table->decimal('closing_credit', 24, 6);

            $table->index(['run_id', 'group_account_id']);
            $table->unique(['run_id', 'company_id', 'account_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION co_reject_run_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Consolidation runs are immutable (CO-FR-09): % on % is not allowed; run the consolidation again instead', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = '55000';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (['co_runs', 'co_run_entities', 'co_run_balances'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION co_reject_run_change()");
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('co_run_balances');
        Schema::dropIfExists('co_run_entities');
        Schema::dropIfExists('co_runs');
        DB::statement('DROP FUNCTION IF EXISTS co_reject_run_change()');
    }
};
