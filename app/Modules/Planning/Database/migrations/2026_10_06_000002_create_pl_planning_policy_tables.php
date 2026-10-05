<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| How a company publishes plans (PL-FR-04): whether the publisher must differ from whoever changed the version
| last, and, per fiscal year, the published version that reports compare against by default. A company without
| a policy row allows the same person to change and publish, and has no default version.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_planning_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->boolean('require_distinct_publisher')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique('company_id');
        });

        Schema::create('pl_default_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'fiscal_year_id', 'gl_fiscal_years');
            CompanyKeys::reference($table, 'plan_version_id', 'pl_plan_versions');
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_default_versions');
        Schema::dropIfExists('pl_planning_policies');
    }
};
