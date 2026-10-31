<?php

use App\Support\Database\CompanyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A route of a transaction type may name its own posting profile (SL-FR-02, TE-FR-10). The document and its open
| item keep that profile, so what settles or revalues the item later finds the same control account; empty
| means the profile of the party, as before.
*/
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sl_documents', 'sl_open_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                CompanyKeys::reference($table, 'posting_profile_id', 'sl_posting_profiles', nullable: true);
            });
        }

        $this->guard(withProfile: true);
    }

    public function down(): void
    {
        $this->guard(withProfile: false);

        foreach (['sl_documents', 'sl_open_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['company_id', 'posting_profile_id']);
                $table->dropColumn('posting_profile_id');
            });
        }
    }

    private function guard(bool $withProfile): void
    {
        $profile = $withProfile ? "OR NEW.posting_profile_id IS DISTINCT FROM OLD.posting_profile_id\n                    " : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION sl_open_items_guard() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id
                    OR NEW.party_id IS DISTINCT FROM OLD.party_id
                    OR NEW.control_role IS DISTINCT FROM OLD.control_role
                    {$profile}OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.side IS DISTINCT FROM OLD.side
                    OR NEW.document_number IS DISTINCT FROM OLD.document_number
                    OR NEW.document_date IS DISTINCT FROM OLD.document_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.original_amount IS DISTINCT FROM OLD.original_amount
                    OR NEW.original_functional IS DISTINCT FROM OLD.original_functional
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate THEN
                    RAISE EXCEPTION 'An open item only changes what remains of it.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);
    }
};
