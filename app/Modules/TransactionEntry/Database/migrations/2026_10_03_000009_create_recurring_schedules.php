<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Recurring journals (TE-FR-14): a template of fixed values, how often it runs and over what range. The
| n-th occurrence is computed from the start date and `run_count`, so a month end never drifts. An entry
| made by a schedule names the schedule and the date it was made for, and the database lets one entry
| hold that pair: a schedule that is run twice for a date still makes one entry.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('te_recurring_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('name', 120);
            $table->string('transaction_type_key', 64);
            $table->unsignedSmallInteger('transaction_type_version')->nullable();
            $table->jsonb('template');
            $table->string('frequency', 12);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('initial_status', 12);
            $table->string('status', 12);
            $table->unsignedInteger('run_count')->default(0);
            $table->date('next_run_on')->nullable();
            $table->date('last_run_on')->nullable();
            $table->char('last_entry_id', 26)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['status', 'next_run_on']);
        });

        DB::statement("ALTER TABLE te_recurring_schedules ADD CONSTRAINT te_recurring_schedules_frequency_check CHECK (frequency IN ('daily', 'weekly', 'monthly', 'quarterly', 'yearly'))");
        DB::statement("ALTER TABLE te_recurring_schedules ADD CONSTRAINT te_recurring_schedules_initial_status_check CHECK (initial_status IN ('draft', 'submitted'))");
        DB::statement("ALTER TABLE te_recurring_schedules ADD CONSTRAINT te_recurring_schedules_status_check CHECK (status IN ('active', 'paused', 'completed'))");

        RetentionGuard::protect('te_recurring_schedules');

        Schema::table('te_entries', function (Blueprint $table) {
            CompanyKeys::reference($table, 'recurring_schedule_id', 'te_recurring_schedules', nullable: true);
            $table->date('recurrence_date')->nullable();
        });

        DB::statement('create unique index te_entries_recurrence_unique on te_entries (recurring_schedule_id, recurrence_date) where recurring_schedule_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index te_entries_recurrence_unique');

        Schema::table('te_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'recurring_schedule_id']);
            $table->dropColumn(['recurring_schedule_id', 'recurrence_date']);
        });

        Schema::dropIfExists('te_recurring_schedules');
    }
};
