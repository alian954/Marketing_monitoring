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
            $table->json('source_medium_breakdown')->nullable();
            $table->boolean('source_medium_breakdown_truncated')->default(false);
        });

        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->json('source_medium_breakdown')->nullable();
            $table->boolean('source_medium_breakdown_truncated')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['source_medium_breakdown', 'source_medium_breakdown_truncated']);
        });

        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropColumn(['source_medium_breakdown', 'source_medium_breakdown_truncated']);
        });
    }
};
