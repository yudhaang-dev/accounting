<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Posting attributes of an account (GL-FR-02). Defaults accept everything, so existing accounts keep working.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_accounts', function (Blueprint $table) {
            $table->jsonb('allowed_sources')->default('["entry","subledger","system"]');
            $table->jsonb('dimension_requirements')->default('{}');
            $table->string('currency_restriction', 16)->default('any');
            $table->string('restricted_currency', 3)->nullable();
            $table->boolean('is_revaluable')->default(false);
        });

        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_currency_restriction_check CHECK (currency_restriction IN ('functional_only', 'specific', 'any'))");
        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_restricted_currency_check CHECK ((currency_restriction = 'specific') = (restricted_currency IS NOT NULL))");
        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_revaluable_check CHECK (NOT is_revaluable OR currency_restriction <> 'functional_only')");
        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_allowed_sources_check CHECK (jsonb_typeof(allowed_sources) = 'array' AND jsonb_array_length(allowed_sources) > 0)");
        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_dimension_requirements_check CHECK (jsonb_typeof(dimension_requirements) = 'object')");
    }

    public function down(): void
    {
        Schema::table('gl_accounts', function (Blueprint $table) {
            $table->dropColumn(['allowed_sources', 'dimension_requirements', 'currency_restriction', 'restricted_currency', 'is_revaluable']);
        });
    }
};
