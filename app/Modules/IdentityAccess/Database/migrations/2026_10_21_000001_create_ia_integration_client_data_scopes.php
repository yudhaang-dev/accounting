<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Data scope of an integration client: the dimension values it is limited to, each standing for itself
| and its descendants (IA-FR-03, IA-FR-06). It works as the scope of a membership does: a client
| without rows is unrestricted, and one with rows can only send, and only read back, what lies inside.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_integration_client_data_scopes', function (Blueprint $table) {
            $table->foreignUlid('integration_client_id')->constrained('ia_integration_clients');
            $table->foreignUlid('company_id')->constrained(CompanyKeys::COMPANIES_TABLE);
            CompanyKeys::reference($table, 'dimension_value_id', 'md_dimension_values');
            $table->timestamp('created_at')->nullable();

            $table->primary(['integration_client_id', 'dimension_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_integration_client_data_scopes');
    }
};
