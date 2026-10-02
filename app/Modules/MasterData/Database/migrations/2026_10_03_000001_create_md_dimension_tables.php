<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Dimension types and values (MD-FR-02, MD-FR-03) are data per company, so a new type needs no schema
| change (MD-AC-03). `md_dimension_set_items` holds the contents of a dimension set (ADR-0007): one
| value per type per set, which also records that a value has been used and may no longer be deleted.
| Resolving combinations into sets arrives with M2-02.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('md_dimension_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('key', 32);
            $table->string('label');
            $table->boolean('is_hierarchical')->default(false);
            $table->boolean('leaf_only')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        DB::statement("ALTER TABLE md_dimension_types ADD CONSTRAINT md_dimension_types_status_check CHECK (status IN ('active', 'inactive'))");

        Schema::create('md_dimension_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'dimension_type_id', 'md_dimension_types');
            $table->string('code', 32);
            $table->string('name');
            $table->ulid('parent_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'dimension_type_id', 'code']);
            // A child must live under a parent of the same type: the parent link carries the type too.
            $table->unique(['company_id', 'dimension_type_id', 'id']);
            $table->foreign(['company_id', 'dimension_type_id', 'parent_id'])
                ->references(['company_id', 'dimension_type_id', 'id'])->on('md_dimension_values');
            $table->index(['company_id', 'parent_id']);
        });

        DB::statement("ALTER TABLE md_dimension_values ADD CONSTRAINT md_dimension_values_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE md_dimension_values ADD CONSTRAINT md_dimension_values_validity_check CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)');

        Schema::create('md_dimension_set_items', function (Blueprint $table) {
            CompanyKeys::reference($table, 'dimension_set_id', 'md_dimension_sets');
            $table->foreignUlid('company_id')->constrained(CompanyKeys::COMPANIES_TABLE);
            $table->ulid('dimension_type_id');
            $table->ulid('dimension_value_id');

            // The value must belong to the type it is filed under.
            $table->foreign(['company_id', 'dimension_type_id', 'dimension_value_id'])
                ->references(['company_id', 'dimension_type_id', 'id'])->on('md_dimension_values');

            $table->primary(['dimension_set_id', 'dimension_type_id']);
            $table->index(['company_id', 'dimension_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('md_dimension_set_items');
        Schema::dropIfExists('md_dimension_values');
        Schema::dropIfExists('md_dimension_types');
    }
};
