<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Consolidation adjustments (CO-FR-08): manual entries on the group accounts of one run, each with a source, a
| reason, and an approval by someone other than who proposed it. They exist only here, never in a ledger of an
| entity (CO-AC-04), and a run reads only the approved ones. The run itself stays untouched (CO-FR-09); the
| adjustment is a record about it with a status that moves once, from pending to approved or rejected. Its lines
| never change.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->foreignUlid('run_id')->constrained('co_runs');
            $table->string('source');
            $table->text('reason');
            $table->string('status', 16);
            $table->unsignedBigInteger('proposed_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
        });

        DB::statement("ALTER TABLE co_adjustments ADD CONSTRAINT co_adjustments_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");

        Schema::create('co_adjustment_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('adjustment_id')->constrained('co_adjustments');
            $table->foreignUlid('group_account_id')->constrained('co_group_accounts');
            $table->string('group_account_code', 32);
            $table->string('group_account_name');
            $table->string('group_account_class', 16);
            $table->decimal('debit', 24, 6);
            $table->decimal('credit', 24, 6);
        });

        DB::statement('ALTER TABLE co_adjustment_lines ADD CONSTRAINT co_adjustment_lines_one_side_check CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND debit + credit > 0)');
        DB::statement('CREATE TRIGGER co_adjustment_lines_immutable BEFORE UPDATE ON co_adjustment_lines FOR EACH ROW EXECUTE FUNCTION co_reject_run_change()');

        foreach (['co_adjustments', 'co_adjustment_lines'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('co_adjustment_lines');
        Schema::dropIfExists('co_adjustments');
    }
};
