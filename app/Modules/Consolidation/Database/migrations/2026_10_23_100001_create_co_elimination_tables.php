<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Elimination of intragroup balances and transactions (CO-FR-07). The group states a nominal tolerance in its
| presentation currency; a run keeps the tolerance it used, the positions that each pair of entities holds against
| each other, the verdict on each pair (matched, or an exception with the difference) and the rows that eliminate
| the matched ones. Nothing here touches a ledger of an entity (CO-AC-04): the eliminations exist only in the
| run. The rows are append-only, like the rest of the run.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('co_groups', function (Blueprint $table) {
            $table->decimal('elimination_tolerance', 24, 6)->default(0);
        });

        DB::statement('ALTER TABLE co_groups ADD CONSTRAINT co_groups_elimination_tolerance_check CHECK (elimination_tolerance >= 0)');

        Schema::table('co_runs', function (Blueprint $table) {
            $table->decimal('elimination_tolerance', 24, 6)->default(0);
        });

        // One verdict per pair of entities and kind: `balance` compares the closing balances on balance sheet
        // accounts, `flow` the movement on income and expense accounts. `company_a_id` is the lower of the two IDs,
        // except for a counterparty outside the perimeter, where it is the company that holds the position.
        Schema::create('co_run_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('co_runs');
            $table->string('kind', 16);
            $table->ulid('company_a_id');
            $table->ulid('company_b_id');
            $table->decimal('amount_a', 24, 6);
            $table->decimal('amount_b', 24, 6);
            $table->decimal('difference', 24, 6);
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            $table->jsonb('positions');

            $table->unique(['run_id', 'kind', 'company_a_id', 'company_b_id']);
        });

        DB::statement("ALTER TABLE co_run_matches ADD CONSTRAINT co_run_matches_kind_check CHECK (kind IN ('balance', 'flow'))");
        DB::statement("ALTER TABLE co_run_matches ADD CONSTRAINT co_run_matches_status_check CHECK (status IN ('matched', 'exception'))");

        // `elimination` reverses one position of a matched pair on the group account it was mapped to; `difference`
        // keeps what a matched pair left within the tolerance, on no account (CO-FR-07: never removed silently).
        Schema::create('co_run_eliminations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('co_runs');
            $table->foreignUlid('match_id')->constrained('co_run_matches');
            $table->string('kind', 16);
            $table->ulid('company_id')->nullable();
            $table->ulid('counterparty_company_id')->nullable();
            $table->ulid('account_id')->nullable();
            $table->string('account_code', 64)->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_class', 16)->nullable();
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
        });

        DB::statement("ALTER TABLE co_run_eliminations ADD CONSTRAINT co_run_eliminations_kind_check CHECK (kind IN ('elimination', 'difference'))");

        foreach (['co_run_matches', 'co_run_eliminations'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION co_reject_run_change()");
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('co_run_eliminations');
        Schema::dropIfExists('co_run_matches');

        Schema::table('co_runs', function (Blueprint $table) {
            $table->dropColumn('elimination_tolerance');
        });

        DB::statement('ALTER TABLE co_groups DROP CONSTRAINT co_groups_elimination_tolerance_check');

        Schema::table('co_groups', function (Blueprint $table) {
            $table->dropColumn('elimination_tolerance');
        });
    }
};
