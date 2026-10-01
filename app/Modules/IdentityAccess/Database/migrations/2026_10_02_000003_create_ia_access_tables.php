<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Roles and permissions for spatie/laravel-permission (teams mode, team = company), with ULID roles
| and company-scoped keys instead of the stub of the package. Table names match config/permission.php.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('ia_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('name');
            $table->string('guard_name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name', 'guard_name']);
        });

        Schema::create('ia_role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->ulid('role_id');

            $table->foreign('permission_id')->references('id')->on('ia_permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('ia_roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('ia_model_has_roles', function (Blueprint $table) {
            $table->ulid('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->ulid('company_id');

            // The role must belong to the same company as the assignment (P-05).
            $table->foreign(['company_id', 'role_id'])->references(['company_id', 'id'])->on('ia_roles');
            $table->primary(['company_id', 'role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_model_has_roles');
        Schema::dropIfExists('ia_role_has_permissions');
        Schema::dropIfExists('ia_roles');
        Schema::dropIfExists('ia_permissions');
    }
};
