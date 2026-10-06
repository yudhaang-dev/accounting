<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A published report is stored as it was computed: the parameters, the version of the definition and
| of the account role mapping it read, the data it covered and the values (RP-FR-14). A snapshot never
| changes or disappears, so changing a definition or a mapping later does not touch it; a recalculation
| is a new snapshot that points at the one it replaces (X-FR-03, X-AC-04, RP-AC-08).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rp_report_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'definition_id', 'rp_report_definitions');
            $table->unsignedInteger('definition_version');
            $table->unsignedInteger('sequence');
            $table->jsonb('parameters');
            $table->jsonb('mapping_versions');
            $table->jsonb('data_boundary');
            $table->jsonb('result');
            $table->string('reason')->nullable();
            $table->ulid('recomputed_from_id')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at');

            $table->unique(['definition_id', 'sequence']);
            $table->index(['company_id', 'definition_id', 'published_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rp_report_snapshots_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A report snapshot cannot change or be deleted.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER rp_report_snapshots_immutable BEFORE UPDATE OR DELETE ON rp_report_snapshots FOR EACH ROW EXECUTE FUNCTION rp_report_snapshots_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS rp_report_snapshots_immutable ON rp_report_snapshots');
        Schema::dropIfExists('rp_report_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS rp_report_snapshots_guard()');
    }
};
