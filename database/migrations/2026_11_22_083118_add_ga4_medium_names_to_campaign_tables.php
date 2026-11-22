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
        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->string('ga4_medium_name', 100)->nullable();
        });

        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->string('medium_name', 100)->nullable();
        });

        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->string('medium_name', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_analytics_campaign_snapshots', function (Blueprint $table): void {
            $table->dropColumn('medium_name');
        });

        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropColumn('medium_name');
        });

        Schema::table('social_engagement_targets', function (Blueprint $table): void {
            $table->dropColumn('ga4_medium_name');
        });
    }
};
