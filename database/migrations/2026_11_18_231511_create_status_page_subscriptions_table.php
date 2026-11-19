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
        Schema::create('status_page_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->text('email')->nullable();
            $table->char('email_hash', 64)->nullable();
            $table->text('confirmation_token')->nullable();
            $table->char('confirmation_token_hash', 64)->nullable();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['status_page_id', 'email_hash'], 'status_page_subscription_email_unique');
            $table->index(['status_page_id', 'confirmed_at', 'unsubscribed_at'], 'status_page_subscription_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_page_subscriptions');
    }
};
