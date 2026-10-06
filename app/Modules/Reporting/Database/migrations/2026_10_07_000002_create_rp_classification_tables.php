<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Reporting's own account role, `cash_equivalents`, and the movement classifications (RP-FR-03). Both
| are versioned and append only, so a snapshot can name the mapping and the rules it was computed
| from (RP-FR-14): a change adds a version and never rewrites an earlier one.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rp_account_role_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('account_role', 32);
            $table->unsignedInteger('version');
            $table->jsonb('account_ids');
            $table->date('effective_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'account_role', 'version']);
        });

        Schema::create('rp_movement_classifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('key', 64);
            $table->string('name');
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('rp_movement_classification_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'classification_id', 'rp_movement_classifications');
            $table->unsignedInteger('version');
            $table->jsonb('structure');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['classification_id', 'version']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rp_versioned_row_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A version cannot change or be deleted.';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (['rp_account_role_mappings', 'rp_movement_classification_versions'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION rp_versioned_row_guard()");
        }
    }

    public function down(): void
    {
        foreach (['rp_account_role_mappings', 'rp_movement_classification_versions'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}");
        }

        Schema::dropIfExists('rp_movement_classification_versions');
        Schema::dropIfExists('rp_movement_classifications');
        Schema::dropIfExists('rp_account_role_mappings');
        DB::statement('DROP FUNCTION IF EXISTS rp_versioned_row_guard()');
    }
};
