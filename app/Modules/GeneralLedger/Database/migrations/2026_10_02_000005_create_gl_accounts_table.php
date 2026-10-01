<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('code', 32);
            $table->string('name');
            $table->string('class', 16);
            $table->string('normal_balance', 8);
            CompanyKeys::reference($table, 'parent_id', 'gl_accounts', nullable: true);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'parent_id']);
        });

        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_class_check CHECK (class IN ('asset', 'liability', 'equity', 'income', 'expense'))");
        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_normal_balance_check CHECK (normal_balance IN ('debit', 'credit'))");
        DB::statement("ALTER TABLE gl_accounts ADD CONSTRAINT gl_accounts_status_check CHECK (status IN ('active', 'inactive'))");

        RetentionGuard::protect('gl_accounts');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};
