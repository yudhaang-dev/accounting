<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A report definition is a named, versioned structure of rows and columns (RP-FR-01, ADR-0009). Every
| change of the structure is a new version and a version never changes or disappears, so a snapshot
| can name the exact structure it was computed from (RP-AC-08).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rp_report_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('key', 64);
            $table->string('name');
            $table->string('kind', 32)->default('custom');
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('rp_report_definition_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'definition_id', 'rp_report_definitions');
            $table->unsignedInteger('version');
            $table->jsonb('structure');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['definition_id', 'version']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rp_report_definition_versions_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A version of a report definition cannot change or be deleted.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER rp_report_definition_versions_immutable BEFORE UPDATE OR DELETE ON rp_report_definition_versions FOR EACH ROW EXECUTE FUNCTION rp_report_definition_versions_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS rp_report_definition_versions_immutable ON rp_report_definition_versions');
        Schema::dropIfExists('rp_report_definition_versions');
        Schema::dropIfExists('rp_report_definitions');
        DB::statement('DROP FUNCTION IF EXISTS rp_report_definition_versions_guard()');
    }
};
