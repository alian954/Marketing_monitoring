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
        Schema::create('marketing_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_engagement_target_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->date('observed_on');
            $table->decimal('observed_value', 18, 4)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('message', 500);
            $table->timestamps();

            $table->unique(['marketing_alert_rule_id', 'social_engagement_target_id', 'source', 'observed_on'], 'marketing_alert_events_dedupe_unique');
            $table->index(['marketing_alert_rule_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_alert_events');
    }
};
