<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A plan is no longer bound to a fiscal year: it has an objective, its own period, and an optional
| scope of one dimension value (ADR-0017). A plan that exists takes the dates of the fiscal year it
| belonged to and no scope, so it behaves as before.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_plans', function (Blueprint $table) {
            $table->text('objective')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            CompanyKeys::reference($table, 'scope_dimension_value_id', 'md_dimension_values', nullable: true);
        });

        DB::statement('UPDATE pl_plans SET starts_on = y.start_date, ends_on = y.end_date FROM gl_fiscal_years y WHERE y.company_id = pl_plans.company_id AND y.id = pl_plans.fiscal_year_id');

        Schema::table('pl_plans', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'fiscal_year_id', 'name']);
            $table->dropForeign(['company_id', 'fiscal_year_id']);
            $table->dropColumn('fiscal_year_id');
        });

        DB::statement('ALTER TABLE pl_plans ALTER COLUMN starts_on SET NOT NULL');
        DB::statement('ALTER TABLE pl_plans ALTER COLUMN ends_on SET NOT NULL');
        DB::statement('ALTER TABLE pl_plans ADD CONSTRAINT pl_plans_period_check CHECK (starts_on <= ends_on)');

        Schema::table('pl_plans', function (Blueprint $table) {
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pl_plans DROP CONSTRAINT IF EXISTS pl_plans_period_check');

        Schema::table('pl_plans', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
        });

        Schema::table('pl_plans', function (Blueprint $table) {
            CompanyKeys::reference($table, 'fiscal_year_id', 'gl_fiscal_years', nullable: true);
        });

        DB::statement('UPDATE pl_plans SET fiscal_year_id = (SELECT y.id FROM gl_fiscal_years y WHERE y.company_id = pl_plans.company_id AND y.start_date <= pl_plans.starts_on ORDER BY y.start_date DESC LIMIT 1)');

        Schema::table('pl_plans', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'scope_dimension_value_id']);
            $table->dropColumn(['objective', 'starts_on', 'ends_on', 'scope_dimension_value_id']);
            $table->unique(['company_id', 'fiscal_year_id', 'name']);
        });
    }
};
