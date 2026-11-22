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
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->string('attribution_mode', 32)->default('manual_utm');
            $table->string('campaign_id', 32)->nullable();
        });

        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->string('attribution_mode', 32)->default('manual_utm');
            $table->string('campaign_id', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['attribution_mode', 'campaign_id']);
        });

        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropColumn(['attribution_mode', 'campaign_id']);
        });
    }
};
