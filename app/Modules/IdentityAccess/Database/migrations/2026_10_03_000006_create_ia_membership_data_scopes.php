<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Data scope of a membership: the dimension values it is limited to, each standing for itself and its
| descendants (IA-FR-03). A membership without rows is unrestricted. An invitation can carry the scope
| the member gets on acceptance, so nobody is unrestricted in between.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_membership_data_scopes', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained('ia_memberships');
            $table->foreignUlid('company_id')->constrained(CompanyKeys::COMPANIES_TABLE);
            CompanyKeys::reference($table, 'dimension_value_id', 'md_dimension_values');
            $table->timestamp('created_at')->nullable();

            $table->primary(['membership_id', 'dimension_value_id']);
        });

        Schema::table('ia_invitations', function (Blueprint $table) {
            $table->jsonb('dimension_value_ids')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('ia_invitations', function (Blueprint $table) {
            $table->dropColumn('dimension_value_ids');
        });
        Schema::dropIfExists('ia_membership_data_scopes');
    }
};
