<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('line_messaging_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('channel_access_token');
            $table->text('channel_secret');
            $table->string('webhook_key', 64)->unique();
            $table->string('bot_user_id', 64)->nullable();
            $table->string('bot_display_name')->nullable();
            $table->string('bot_basic_id', 64)->nullable();
            $table->timestamp('last_webhook_received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('line_notification_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_messaging_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 10);
            $table->string('target_id', 64);
            $table->string('display_name')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['line_messaging_channel_id', 'target_id']);
            $table->index(['workspace_id', 'left_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('line_notification_targets');
        Schema::dropIfExists('line_messaging_channels');
    }
};
