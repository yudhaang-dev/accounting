<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Fiscal calendar, fiscal years and periods (GL-FR-03, GL-FR-04). Periods of a company never overlap
| (exclusion constraint, ADR-0003) and never leave a gap (trigger): a new range must start the day
| after the last one of the company, or end the day before the first one.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_fiscal_calendars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('pattern', 16);
            $table->unsignedSmallInteger('start_month');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            // One calendar per company.
            $table->unique('company_id');
        });

        Schema::create('gl_fiscal_years', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'calendar_id', 'gl_fiscal_calendars');
            $table->string('name', 32);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('gl_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'fiscal_year_id', 'gl_fiscal_years');
            $table->unsignedSmallInteger('number');
            $table->string('name', 32);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'number']);
            $table->index(['company_id', 'start_date']);
        });

        DB::statement("ALTER TABLE gl_fiscal_calendars ADD CONSTRAINT gl_fiscal_calendars_pattern_check CHECK (pattern IN ('monthly'))");
        DB::statement('ALTER TABLE gl_fiscal_calendars ADD CONSTRAINT gl_fiscal_calendars_start_month_check CHECK (start_month BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE gl_fiscal_years ADD CONSTRAINT gl_fiscal_years_range_check CHECK (end_date > start_date)');
        DB::statement('ALTER TABLE gl_periods ADD CONSTRAINT gl_periods_range_check CHECK (end_date >= start_date)');
        DB::statement("ALTER TABLE gl_periods ADD CONSTRAINT gl_periods_status_check CHECK (status IN ('open', 'closed'))");

        foreach (['gl_fiscal_years', 'gl_periods'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_no_overlap EXCLUDE USING gist (company_id WITH =, daterange(start_date, end_date, '[]') WITH &&)");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gl_require_adjacent_range() RETURNS trigger AS $$
            DECLARE
                last_end date;
                first_start date;
            BEGIN
                EXECUTE format('SELECT max(end_date), min(start_date) FROM %I WHERE company_id = $1', TG_TABLE_NAME)
                    INTO last_end, first_start USING NEW.company_id;

                IF last_end IS NOT NULL AND NEW.start_date <> last_end + 1 AND NEW.end_date <> first_start - 1 THEN
                    RAISE EXCEPTION '% must be contiguous: a new range follows the last one or precedes the first one', TG_TABLE_NAME
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (['gl_fiscal_years', 'gl_periods'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_contiguous BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION gl_require_adjacent_range()");
        }

        foreach (['gl_fiscal_calendars', 'gl_fiscal_years', 'gl_periods'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_periods');
        Schema::dropIfExists('gl_fiscal_years');
        Schema::dropIfExists('gl_fiscal_calendars');
        DB::statement('DROP FUNCTION IF EXISTS gl_require_adjacent_range()');
    }
};
