<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| A loan is a document that raises a balance and moves cash at once (SL-FR-04): an employee's advance, a loan
| given or a loan received. It takes a cash account like a payment does, so the two checks of the table
| that name the kinds learn it.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_kind_check');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_kind_check CHECK (kind IN ('invoice', 'credit_note', 'payment', 'advance', 'write_off', 'loan'))");
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_cash_check');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_cash_check CHECK ((kind IN ('payment', 'advance', 'loan')) = (cash_account_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_cash_check');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_cash_check CHECK ((kind IN ('payment', 'advance')) = (cash_account_id IS NOT NULL))");
        DB::statement('ALTER TABLE sl_documents DROP CONSTRAINT sl_documents_kind_check');
        DB::statement("ALTER TABLE sl_documents ADD CONSTRAINT sl_documents_kind_check CHECK (kind IN ('invoice', 'credit_note', 'payment', 'advance', 'write_off'))");
    }
};
