<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Party roles are data of the company (SL-FR-01, ADR-0015): a stable key and a label, and a party may
| hold several. They replace the flags `is_customer` and `is_supplier`, whose values become the roles
| `customer` and `supplier` of the companies that had them.
*/
return new class extends Migration
{
    private const LEGACY = ['is_customer' => ['customer', 'Pelanggan'], 'is_supplier' => ['supplier', 'Pemasok']];

    public function up(): void
    {
        Schema::create('sl_party_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('key', 32);
            $table->string('label');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        DB::statement("ALTER TABLE sl_party_roles ADD CONSTRAINT sl_party_roles_status_check CHECK (status IN ('active', 'inactive'))");

        Schema::create('sl_party_role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'party_id', 'sl_parties');
            CompanyKeys::reference($table, 'party_role_id', 'sl_party_roles');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'party_id', 'party_role_id']);
            $table->index(['company_id', 'party_role_id']);
        });

        RetentionGuard::protect('sl_party_roles');

        $now = now();

        foreach (self::LEGACY as $column => [$key, $label]) {
            foreach (DB::table('sl_parties')->where($column, true)->distinct()->pluck('company_id') as $companyId) {
                $roleId = (string) Str::ulid();
                DB::table('sl_party_roles')->insert(['id' => $roleId, 'company_id' => $companyId, 'key' => $key, 'label' => $label, 'status' => 'active', 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);

                foreach (DB::table('sl_parties')->where('company_id', $companyId)->where($column, true)->pluck('id') as $partyId) {
                    DB::table('sl_party_role_assignments')->insert(['id' => (string) Str::ulid(), 'company_id' => $companyId, 'party_id' => $partyId, 'party_role_id' => $roleId, 'created_at' => $now]);
                }
            }
        }

        DB::statement('ALTER TABLE sl_parties DROP CONSTRAINT sl_parties_role_check');

        Schema::table('sl_parties', function (Blueprint $table) {
            $table->dropColumn(['is_customer', 'is_supplier']);
        });
    }

    public function down(): void
    {
        Schema::table('sl_parties', function (Blueprint $table) {
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
        });

        foreach (self::LEGACY as $column => [$key]) {
            DB::statement("UPDATE sl_parties SET {$column} = true WHERE EXISTS (SELECT 1 FROM sl_party_role_assignments a JOIN sl_party_roles r ON r.company_id = a.company_id AND r.id = a.party_role_id WHERE a.company_id = sl_parties.company_id AND a.party_id = sl_parties.id AND r.key = '{$key}')");
        }

        DB::statement('UPDATE sl_parties SET is_customer = true WHERE NOT is_customer AND NOT is_supplier');
        DB::statement('ALTER TABLE sl_parties ADD CONSTRAINT sl_parties_role_check CHECK (is_customer OR is_supplier)');

        Schema::dropIfExists('sl_party_role_assignments');
        Schema::dropIfExists('sl_party_roles');
    }
};
