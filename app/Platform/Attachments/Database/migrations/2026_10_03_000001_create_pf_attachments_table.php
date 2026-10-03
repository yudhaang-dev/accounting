<?php

use App\Platform\Retention\RetentionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| File metadata and the hash of the content (X-FR-04). The bytes live on the attachments disk. A subject
| is any record of a business module, named by a type and an ID, so this table knows no module.
| Removing an attachment only marks it: the row stays for its retention period (X-FR-05).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pf_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id')->index();
            $table->string('subject_type', 64);
            $table->ulid('subject_id');
            $table->string('filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        RetentionGuard::protect('pf_attachments');
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_attachments');
    }
};
