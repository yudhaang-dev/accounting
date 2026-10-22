<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The perimeter of a group (CO-FR-02) and the mapping of company accounts to the chart of the group
| (CO-FR-04, P-04).
|
| A member is proposed with the reasoning behind the control assessment, and counts only after someone with
| the approval ability, not the proposer, approves it: control is a judgement of the accountant and is never
| inferred from memberships or percentages. A company is in the perimeter once at a time; leaving it keeps the
| row, and joining again is a new row.
|
| The mapping is versioned as a whole: saving it adds a version holding every row, so a consolidation run can
| name the version it read and a later change leaves that run as it was (CO-FR-09).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_perimeter_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->foreignUlid('company_id')->constrained('md_companies');
            $table->text('control_basis');
            $table->string('treatment', 16)->default('full');
            $table->string('status', 16)->default('pending');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('proposed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['group_id', 'status']);
        });

        DB::statement("ALTER TABLE co_perimeter_members ADD CONSTRAINT co_perimeter_members_status_check CHECK (status IN ('pending', 'approved', 'ended'))");
        DB::statement("ALTER TABLE co_perimeter_members ADD CONSTRAINT co_perimeter_members_treatment_check CHECK (treatment IN ('full'))");
        DB::statement('ALTER TABLE co_perimeter_members ADD CONSTRAINT co_perimeter_members_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        DB::statement("CREATE UNIQUE INDEX co_perimeter_members_one_active ON co_perimeter_members (group_id, company_id) WHERE status <> 'ended'");

        Schema::create('co_mapping_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('co_groups');
            $table->unsignedInteger('version');
            $table->date('effective_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['group_id', 'version']);
        });

        Schema::create('co_mapping_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('mapping_version_id')->constrained('co_mapping_versions');
            $table->foreignUlid('company_id')->constrained('md_companies');
            // The account of the company, by ID and with the code it had when it was mapped, for the trail.
            $table->ulid('account_id');
            $table->string('account_code', 64);
            $table->foreignUlid('group_account_id')->constrained('co_group_accounts');

            $table->unique(['mapping_version_id', 'company_id', 'account_id']);
        });

        foreach (['co_perimeter_members', 'co_mapping_versions', 'co_mapping_rows'] as $table) {
            RetentionGuard::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('co_mapping_rows');
        Schema::dropIfExists('co_mapping_versions');
        Schema::dropIfExists('co_perimeter_members');
    }
};
