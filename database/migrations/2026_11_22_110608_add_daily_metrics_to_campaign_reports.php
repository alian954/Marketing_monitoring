<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->json('daily_metrics')->nullable();
        });

        Schema::table('social_engagement_target_meta_marketing_reports', function (Blueprint $table): void {
            $table->json('daily_metrics')->nullable();
        });

        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table): void {
            $table->json('daily_metrics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropColumn('daily_metrics');
        });

        Schema::table('social_engagement_target_meta_marketing_reports', function (Blueprint $table): void {
            $table->dropColumn('daily_metrics');
        });

        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table): void {
            $table->dropColumn('daily_metrics');
        });
    }
};
