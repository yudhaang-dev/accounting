<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The changes feed (TE-FR-15) reads the approval trail in the order of a per-company sequence. The
| sequence is taken from a counter row that stays locked until the transaction ends, so numbers become
| visible in the order they are given: a poller that has seen number n will never see a smaller one
| appear later. `status` is the status of the entry after the step.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('te_decision_sequences', function (Blueprint $table) {
            $table->foreignUlid('company_id')->primary()->constrained('md_companies');
            $table->unsignedBigInteger('last');
        });

        RetentionGuard::protect('te_decision_sequences');

        Schema::table('te_entry_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable();
            $table->string('status', 16)->nullable();
        });

        DB::statement('update te_entry_decisions d set seq = r.n from (select id, row_number() over (partition by company_id order by created_at, id) as n from te_entry_decisions) r where r.id = d.id');
        DB::statement('update te_entry_decisions d set status = e.status from te_entries e where e.id = d.entry_id');
        DB::statement('insert into te_decision_sequences (company_id, last) select company_id, max(seq) from te_entry_decisions group by company_id');

        Schema::table('te_entry_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable(false)->change();
            $table->string('status', 16)->nullable(false)->change();
            $table->unique(['company_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::table('te_entry_decisions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'seq']);
            $table->dropColumn(['seq', 'status']);
        });

        Schema::dropIfExists('te_decision_sequences');
    }
};
