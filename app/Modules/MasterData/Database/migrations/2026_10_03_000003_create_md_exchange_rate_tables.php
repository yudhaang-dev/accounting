<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Rate types are data per company (MD-FR-07, P-01). `max_age_days` is the optional age limit of the
| rates of a type (MD-FR-08). Rates are append-only: a correction is a new version of the same
| (type, currency, effective date), and lookups use the latest version, so journals that stored
| the rate they used are never affected.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('md_rate_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('key', 32);
            $table->string('label');
            $table->unsignedSmallInteger('max_age_days')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        DB::statement("ALTER TABLE md_rate_types ADD CONSTRAINT md_rate_types_status_check CHECK (status IN ('active', 'inactive'))");

        Schema::create('md_exchange_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'rate_type_id', 'md_rate_types');
            $table->char('currency', 3);
            $table->date('effective_date');
            // Functional currency per 1 unit of the foreign currency (MD-FR-07).
            $table->decimal('rate', 24, 12);
            $table->unsignedInteger('version');
            $table->string('source', 16);
            $table->string('actor_type', 64)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign(['company_id', 'currency'])->references(['company_id', 'currency'])->on('md_company_currencies');
            $table->unique(['company_id', 'rate_type_id', 'currency', 'effective_date', 'version'], 'md_exchange_rates_version_unique');
            $table->index(['company_id', 'rate_type_id', 'currency', 'effective_date'], 'md_exchange_rates_lookup');
        });

        DB::statement('ALTER TABLE md_exchange_rates ADD CONSTRAINT md_exchange_rates_rate_check CHECK (rate > 0)');
        DB::statement("ALTER TABLE md_exchange_rates ADD CONSTRAINT md_exchange_rates_source_check CHECK (source IN ('manual', 'csv', 'api'))");
        DB::statement('ALTER TABLE md_exchange_rates ADD CONSTRAINT md_exchange_rates_version_check CHECK (version >= 1)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION md_exchange_rates_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Exchange rates are append-only; record a new version instead.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER md_exchange_rates_no_update BEFORE UPDATE OR DELETE ON md_exchange_rates FOR EACH ROW EXECUTE FUNCTION md_exchange_rates_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS md_exchange_rates_no_update ON md_exchange_rates');
        DB::statement('DROP FUNCTION IF EXISTS md_exchange_rates_immutable()');
        Schema::dropIfExists('md_exchange_rates');
        Schema::dropIfExists('md_rate_types');
    }
};
