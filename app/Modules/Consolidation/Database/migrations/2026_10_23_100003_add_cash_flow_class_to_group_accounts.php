<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| How an account of the group enters the group cash flow (CO-FR-10): `cash` for the accounts that make up cash and
| cash equivalents, otherwise the activity its changes belong to. An account of equity without one counts as
| financing; an asset or liability without one is unclassified and the cash flow says so. Income and expense
| accounts have none: they enter through the result.
|
| A run keeps the class its accounts had then, like their code and name, so the cash flow of a run reads the
| same after the classification is changed (CO-FR-09).
*/
return new class extends Migration
{
    private const CLASSES = ['co_run_balances', 'co_run_eliminations', 'co_adjustment_lines'];

    public function up(): void
    {
        Schema::table('co_group_accounts', function (Blueprint $table) {
            $table->string('cash_flow_class', 16)->nullable();
        });

        DB::statement("ALTER TABLE co_group_accounts ADD CONSTRAINT co_group_accounts_cash_flow_class_check CHECK (cash_flow_class IS NULL OR (cash_flow_class IN ('cash', 'operating', 'investing', 'financing') AND class IN ('asset', 'liability', 'equity') AND (cash_flow_class <> 'cash' OR class = 'asset')))");

        foreach (self::CLASSES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('group_account_cash_flow_class', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::CLASSES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('group_account_cash_flow_class');
            });
        }

        DB::statement('ALTER TABLE co_group_accounts DROP CONSTRAINT co_group_accounts_cash_flow_class_check');

        Schema::table('co_group_accounts', function (Blueprint $table) {
            $table->dropColumn('cash_flow_class');
        });
    }
};
