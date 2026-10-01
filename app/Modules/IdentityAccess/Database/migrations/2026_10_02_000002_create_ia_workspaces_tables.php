<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            // One company per workspace in the MVP (IA-FR-01).
            $table->foreignUlid('company_id')->unique()->constrained('md_companies');
            $table->timestamps();
        });

        Schema::create('ia_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('ia_workspaces');
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 16)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_memberships');
        Schema::dropIfExists('ia_workspaces');
    }
};
