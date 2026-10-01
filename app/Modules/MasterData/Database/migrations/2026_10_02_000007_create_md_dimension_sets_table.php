<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A dimension set is one unique combination of dimension values per company (ADR-0007). Journal lines
| and balances only store its ID. This table starts with the empty combination; the values of a set
| and the resolution of combinations arrive with M2-02 without changing these keys.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('md_dimension_sets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            CompanyKeys::scoped($table);
            // sha256 of the canonical combination; the empty combination hashes the empty string.
            $table->char('signature', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('md_dimension_sets');
    }
};
