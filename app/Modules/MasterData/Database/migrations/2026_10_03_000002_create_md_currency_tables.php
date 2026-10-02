<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| `md_currencies` is the ISO 4217 reference, shared by all companies and loaded here. A company
| activates the currencies it uses in `md_company_currencies` with its own scale and rounding mode
| (MD-FR-06); the ISO minor unit is only the suggestion. The functional currency is always active, so
| companies that already exist get a row for it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('md_currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->unsignedSmallInteger('minor_unit');
        });

        Schema::create('md_company_currencies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('scale');
            $table->string('rounding_mode', 16)->default('half_up');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign('currency')->references('code')->on('md_currencies');
            $table->unique(['company_id', 'currency']);
        });

        DB::statement('ALTER TABLE md_company_currencies ADD CONSTRAINT md_company_currencies_scale_check CHECK (scale BETWEEN 0 AND 6)');
        DB::statement("ALTER TABLE md_company_currencies ADD CONSTRAINT md_company_currencies_rounding_check CHECK (rounding_mode IN ('half_up', 'half_even', 'up', 'down'))");
        DB::statement("ALTER TABLE md_company_currencies ADD CONSTRAINT md_company_currencies_status_check CHECK (status IN ('active', 'inactive'))");

        /** @var array<string, array{0: string, 1: int}> $iso */
        $iso = require __DIR__.'/../data/iso-4217.php';

        foreach ($iso as $code => [$name, $minorUnit]) {
            DB::table('md_currencies')->insert(['code' => $code, 'name' => $name, 'minor_unit' => $minorUnit]);
        }

        foreach (DB::table('md_companies')->get(['id', 'functional_currency']) as $company) {
            $known = $iso[$company->functional_currency] ?? null;

            if ($known === null) {
                continue;
            }

            DB::table('md_company_currencies')->insert([
                'id' => (string) Str::ulid(),
                'company_id' => $company->id,
                'currency' => $company->functional_currency,
                'scale' => $known[1],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('md_company_currencies');
        Schema::dropIfExists('md_currencies');
    }
};
