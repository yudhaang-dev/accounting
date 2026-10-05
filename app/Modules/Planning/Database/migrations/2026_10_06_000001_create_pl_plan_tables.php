<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A plan holds the revenue and expense projection of one fiscal year in named versions (PL-FR-01).
| A version that is not a draft never changes its lines, and a version is never deleted once it has
| left draft, so the history of what was planned is never overwritten. Plan lines are entered and
| validated from M7-02; the table is here because revising a version copies them.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('name');
            CompanyKeys::reference($table, 'fiscal_year_id', 'gl_fiscal_years');
            $table->text('description')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'name']);
        });

        Schema::create('pl_plan_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'plan_id', 'pl_plans');
            $table->string('name');
            $table->string('status', 16)->default('draft');
            // The version this one was copied from when a published version was revised.
            CompanyKeys::reference($table, 'origin_version_id', 'pl_plan_versions', nullable: true);
            // Who changed the version last; the publisher policy compares it with the publisher (PL-FR-04).
            $table->unsignedBigInteger('last_modified_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'plan_id', 'name']);
        });

        DB::statement("ALTER TABLE pl_plan_versions ADD CONSTRAINT pl_plan_versions_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("ALTER TABLE pl_plan_versions ADD CONSTRAINT pl_plan_versions_published_check CHECK ((status = 'draft') = (published_at IS NULL))");

        Schema::create('pl_plan_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'plan_version_id', 'pl_plan_versions');
            CompanyKeys::reference($table, 'period_id', 'gl_periods');
            CompanyKeys::reference($table, 'account_id', 'gl_accounts');
            CompanyKeys::reference($table, 'dimension_set_id', 'md_dimension_sets');
            // In the functional currency of the company (PL-FR-02).
            $table->decimal('amount', 24, 6);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['plan_version_id', 'period_id', 'account_id', 'dimension_set_id'], 'pl_plan_lines_cell_unique');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pl_plan_lines_guard() RETURNS trigger AS $$
            DECLARE
                version_id char(26);
                version_status varchar(16);
            BEGIN
                version_id := CASE WHEN TG_OP = 'INSERT' THEN NEW.plan_version_id ELSE OLD.plan_version_id END;
                SELECT status INTO version_status FROM pl_plan_versions WHERE id = version_id;

                IF version_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Lines of a plan version that is not a draft cannot change.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER pl_plan_lines_only_draft BEFORE INSERT OR UPDATE OR DELETE ON pl_plan_lines FOR EACH ROW EXECUTE FUNCTION pl_plan_lines_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pl_plan_versions_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'A plan version that has left draft cannot be deleted.';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'draft' AND (
                    NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.plan_id IS DISTINCT FROM OLD.plan_id
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.origin_version_id IS DISTINCT FROM OLD.origin_version_id
                    OR NEW.published_by IS DISTINCT FROM OLD.published_by
                    OR NEW.published_at IS DISTINCT FROM OLD.published_at
                    OR NEW.status = 'draft') THEN
                    RAISE EXCEPTION 'A plan version that has left draft only changes between published and archived.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER pl_plan_versions_history BEFORE UPDATE OR DELETE ON pl_plan_versions FOR EACH ROW EXECUTE FUNCTION pl_plan_versions_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS pl_plan_versions_history ON pl_plan_versions');
        DB::statement('DROP TRIGGER IF EXISTS pl_plan_lines_only_draft ON pl_plan_lines');
        Schema::dropIfExists('pl_plan_lines');
        Schema::dropIfExists('pl_plan_versions');
        Schema::dropIfExists('pl_plans');
        DB::statement('DROP FUNCTION IF EXISTS pl_plan_versions_guard()');
        DB::statement('DROP FUNCTION IF EXISTS pl_plan_lines_guard()');
    }
};
