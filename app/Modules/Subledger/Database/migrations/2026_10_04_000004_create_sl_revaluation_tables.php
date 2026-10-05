<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A revaluation of the foreign currency open items at the end of a period (SL-FR-07). A run keeps the journal
| it posted, its reversal when the policy reverses, and what it adjusted per open item, so that running the
| period again only posts what changed since and never adds the same difference twice.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_revaluation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'period_id', 'gl_periods');
            CompanyKeys::reference($table, 'journal_id', 'gl_journals');
            CompanyKeys::reference($table, 'reversal_journal_id', 'gl_journals', nullable: true);
            $table->string('rate_type_key', 32);
            $table->boolean('auto_reverse');
            $table->unsignedBigInteger('run_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'period_id']);
        });

        Schema::create('sl_revaluation_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            CompanyKeys::reference($table, 'run_id', 'sl_revaluation_runs');
            CompanyKeys::reference($table, 'open_item_id', 'sl_open_items');
            $table->decimal('remaining_amount', 24, 6);
            $table->decimal('rate', 24, 12);
            // Added to the functional value the item carried: positive when the item is worth more.
            $table->decimal('adjustment', 24, 6);
            $table->timestamps();

            $table->index(['company_id', 'open_item_id']);
        });

        foreach (['sl_revaluation_runs', 'sl_revaluation_items'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sl_revaluation_items');
        Schema::dropIfExists('sl_revaluation_runs');
    }
};
