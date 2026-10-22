<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| What the translation of a company in another functional currency needs (CO-FR-06, ADR-0014): the rate types
| of the group, the rates of those types, and the translation policy that says which type applies to which
| part of the statements and which equity account takes the translation difference.
|
| The policy is versioned as a whole, like the account mapping, so a run can name the version it used. Rates
| are append-only: a correction is a new version of the same type, currency and date, and lookups use the
| latest one, so a run that read an earlier version stays reproducible.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_rate_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['group_id', 'code']);
        });

        Schema::create('co_translation_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->unsignedInteger('version');
            $table->date('effective_date');
            // Assets and liabilities at the closing rate, income and expenses at the average rate of the
            // period, equity at the historical rate (ADR-0014).
            $table->foreignUlid('closing_rate_type_id')->constrained('co_rate_types');
            $table->foreignUlid('average_rate_type_id')->constrained('co_rate_types');
            $table->foreignUlid('historical_rate_type_id')->constrained('co_rate_types');
            $table->foreignUlid('difference_account_id')->constrained('co_group_accounts');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['group_id', 'version']);
        });

        Schema::create('co_group_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->foreignUlid('rate_type_id')->constrained('co_rate_types');
            $table->char('currency', 3);
            $table->date('effective_date');
            // Presentation currency per 1 unit of the currency.
            $table->decimal('rate', 24, 12);
            $table->unsignedInteger('version');
            $table->string('source', 16);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['rate_type_id', 'currency', 'effective_date', 'version'], 'co_group_rates_version_unique');
            $table->index(['group_id', 'rate_type_id', 'currency', 'effective_date'], 'co_group_rates_lookup');
        });

        DB::statement('ALTER TABLE co_group_rates ADD CONSTRAINT co_group_rates_rate_check CHECK (rate > 0)');
        DB::statement("ALTER TABLE co_group_rates ADD CONSTRAINT co_group_rates_source_check CHECK (source IN ('manual', 'api'))");
        DB::statement('ALTER TABLE co_group_rates ADD CONSTRAINT co_group_rates_version_check CHECK (version >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION co_group_rates_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Group rates are append-only; record a new version instead.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER co_group_rates_no_update BEFORE UPDATE ON co_group_rates FOR EACH ROW EXECUTE FUNCTION co_group_rates_immutable()');

        foreach (['co_rate_types', 'co_translation_policies', 'co_group_rates'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS co_group_rates_no_update ON co_group_rates');
        DB::statement('DROP FUNCTION IF EXISTS co_group_rates_immutable()');

        Schema::dropIfExists('co_group_rates');
        Schema::dropIfExists('co_translation_policies');
        Schema::dropIfExists('co_rate_types');
    }
};
