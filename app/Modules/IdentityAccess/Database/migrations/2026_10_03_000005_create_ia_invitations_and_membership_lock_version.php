<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ia_memberships', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
        });

        // An invitation turns into a membership when the invited person accepts it (IA-FR-01). Only
        // the hash of the token is stored; the token itself travels by email.
        Schema::create('ia_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('ia_workspaces');
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->jsonb('role_ids');
            $table->timestamp('membership_expires_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('status', 16)->default('pending');
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_invitations');
        Schema::table('ia_memberships', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
