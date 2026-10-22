<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A consolidation group (CO-FR-02) and its own chart of accounts (CO-FR-04). The group belongs to no company:
| it names a parent company, a presentation currency and the companies of its perimeter, and who may read or
| run it is decided by the grants of IdentityAccess (IA-FR-07). Nothing here is ever deleted (X-FR-05): a
| group account that is not used any more is deactivated.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->foreignUlid('parent_company_id')->constrained('md_companies');
            $table->char('presentation_currency', 3);
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('co_group_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->string('code', 32);
            $table->string('name');
            $table->string('class', 16);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['group_id', 'code']);
        });

        DB::statement("ALTER TABLE co_group_accounts ADD CONSTRAINT co_group_accounts_class_check CHECK (class IN ('asset', 'liability', 'equity', 'income', 'expense'))");

        foreach (['co_groups', 'co_group_accounts'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('co_group_accounts');
        Schema::dropIfExists('co_groups');
    }
};
