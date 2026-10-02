<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| `soft_closed` periods (GL-FR-04), the 4-4-5 and free-range calendar patterns (GL-FR-03) and the
| versioned ledger policy that says what a soft-closed period still accepts (GL-AC-04, P-04). Like
| the security policy, a change adds a row and the highest version is current; no row means that a
| soft-closed period accepts nothing.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gl_periods DROP CONSTRAINT gl_periods_status_check');
        DB::statement("ALTER TABLE gl_periods ADD CONSTRAINT gl_periods_status_check CHECK (status IN ('open', 'soft_closed', 'closed'))");

        DB::statement('ALTER TABLE gl_fiscal_calendars DROP CONSTRAINT gl_fiscal_calendars_pattern_check');
        DB::statement("ALTER TABLE gl_fiscal_calendars ADD CONSTRAINT gl_fiscal_calendars_pattern_check CHECK (pattern IN ('monthly', 'four_four_five', 'free'))");
        DB::statement('ALTER TABLE gl_fiscal_calendars ALTER COLUMN start_month DROP NOT NULL');
        // Only a monthly calendar starts in a given month; the other patterns start on the date of the first year.
        DB::statement("ALTER TABLE gl_fiscal_calendars ADD CONSTRAINT gl_fiscal_calendars_start_month_pattern_check CHECK ((pattern = 'monthly') = (start_month IS NOT NULL))");

        Schema::create('gl_ledger_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->unsignedInteger('version');
            // Transaction type keys that may still post into a soft-closed period.
            $table->jsonb('soft_closed_transaction_type_keys');
            // Permission the poster must also hold; null means the key alone is enough.
            $table->string('soft_closed_permission', 64)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');

            $table->unique(['company_id', 'version']);
        });

        RetentionGuard::protect('gl_ledger_policies');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_ledger_policies');
        DB::statement('ALTER TABLE gl_fiscal_calendars DROP CONSTRAINT gl_fiscal_calendars_start_month_pattern_check');
        DB::statement('ALTER TABLE gl_periods DROP CONSTRAINT gl_periods_status_check');
        DB::statement("ALTER TABLE gl_periods ADD CONSTRAINT gl_periods_status_check CHECK (status IN ('open', 'closed'))");
    }
};
