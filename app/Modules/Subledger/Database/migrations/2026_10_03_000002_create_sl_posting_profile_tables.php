<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A posting profile names the control accounts of the parties that use it (SL-FR-02). The account
| of a control role is versioned: a change adds a row, so a posting can name the version it used.
| A party points to a profile; without one it uses the company's default profile.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_posting_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX sl_posting_profiles_one_default ON sl_posting_profiles (company_id) WHERE is_default');

        Schema::create('sl_posting_profile_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'posting_profile_id', 'sl_posting_profiles');
            $table->string('control_role', 32);
            $table->unsignedInteger('version');
            CompanyKeys::reference($table, 'account_id', 'gl_accounts');
            $table->date('effective_date');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'posting_profile_id', 'control_role', 'version'], 'sl_posting_profile_accounts_version_unique');
        });

        DB::statement("ALTER TABLE sl_posting_profile_accounts ADD CONSTRAINT sl_posting_profile_accounts_role_check CHECK (control_role IN ('receivables_control', 'payables_control'))");

        Schema::table('sl_parties', function (Blueprint $table) {
            CompanyKeys::reference($table, 'posting_profile_id', 'sl_posting_profiles', nullable: true);
        });

        foreach (['sl_posting_profiles', 'sl_posting_profile_accounts'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::table('sl_parties', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'posting_profile_id']);
            $table->dropColumn('posting_profile_id');
        });
        Schema::dropIfExists('sl_posting_profile_accounts');
        Schema::dropIfExists('sl_posting_profiles');
    }
};
