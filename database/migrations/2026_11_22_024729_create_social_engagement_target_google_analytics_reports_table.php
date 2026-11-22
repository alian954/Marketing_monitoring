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
        Schema::create('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('google_analytics_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_engagement_target_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_name', 100);
            $table->string('source_name', 100)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('sessions');
            $table->unsignedBigInteger('engaged_sessions');
            $table->decimal('key_events', 20, 4);
            $table->decimal('total_revenue', 20, 4);
            $table->char('currency_code', 3)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['google_analytics_connection_id', 'social_engagement_target_id'], 'ga4_campaign_report_connection_target_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_engagement_target_google_analytics_reports');
    }
};
