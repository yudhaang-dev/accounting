<?php

use App\Platform\Retention\RetentionGuard;
use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| How receivables and payables are aged (SL-FR-08): the date the age counts from and the upper bound, in days, of
| each bucket. It is data, so changing the buckets changes no code and no stored amount (SL-AC-04). A company
| without a row ages by the due date in buckets of 30, 60 and 90 days.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sl_aging_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            $table->string('basis', 16);
            $table->jsonb('buckets');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique('company_id');
        });

        DB::statement("ALTER TABLE sl_aging_policies ADD CONSTRAINT sl_aging_policies_basis_check CHECK (basis IN ('due_date', 'document_date'))");

        RetentionGuard::protect('sl_aging_policies');
    }

    public function down(): void
    {
        Schema::dropIfExists('sl_aging_policies');
    }
};
