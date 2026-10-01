<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company's own retention period (P-01). No row means the configured default applies.
        Schema::create('pf_retention_policies', function (Blueprint $table) {
            $table->ulid('company_id')->primary();
            $table->unsignedSmallInteger('years');
            $table->timestampsTz();
        });

        // Shared by every protected table. Hard deletes are refused unless the transaction was opened
        // by the retention purge (Retention::purging), which only runs for records past their period.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pf_reject_hard_delete() RETURNS trigger AS $$
            BEGIN
                IF current_setting('app.retention_purge', true) = 'on' THEN
                    IF TG_LEVEL = 'ROW' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NULL;
                END IF;

                RAISE EXCEPTION '% on % is not allowed: financial data is kept for its retention period', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_retention_policies');
        DB::statement('DROP FUNCTION IF EXISTS pf_reject_hard_delete()');
    }
};
