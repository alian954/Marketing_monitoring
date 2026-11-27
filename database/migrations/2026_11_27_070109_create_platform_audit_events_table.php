<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only governance trail for platform and workspace administration.
     *
     * References are nulled rather than cascaded so events outlive the records they describe.
     */
    public function up(): void
    {
        Schema::create('platform_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
    }
};
