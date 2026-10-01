<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pf_audit_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id')->nullable()->index();
            $table->string('log_name')->nullable()->index();
            $table->string('event')->nullable()->index();
            $table->text('description');
            $table->string('outcome', 16)->default('success');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index(['company_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pf_audit_log_reject_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pf_audit_log is append-only (% not allowed)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER pf_audit_log_no_update_delete BEFORE UPDATE OR DELETE ON pf_audit_log FOR EACH ROW EXECUTE FUNCTION pf_audit_log_reject_change()');
        DB::statement('CREATE TRIGGER pf_audit_log_no_truncate BEFORE TRUNCATE ON pf_audit_log FOR EACH STATEMENT EXECUTE FUNCTION pf_audit_log_reject_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_audit_log');
        DB::statement('DROP FUNCTION IF EXISTS pf_audit_log_reject_change()');
    }
};
