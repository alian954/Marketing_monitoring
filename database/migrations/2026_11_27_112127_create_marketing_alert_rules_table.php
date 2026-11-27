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
        Schema::create('marketing_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->decimal('threshold', 18, 4)->nullable();
            $table->text('recipients')->nullable();
            $table->text('webhook_url')->nullable();
            $table->string('webhook_channel', 20)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'paused_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_alert_rules');
    }
};
