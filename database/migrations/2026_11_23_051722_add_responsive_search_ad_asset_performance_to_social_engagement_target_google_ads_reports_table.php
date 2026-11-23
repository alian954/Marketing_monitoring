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
        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table) {
            $table->longText('responsive_search_ad_asset_performance')->nullable();
            $table->timestamp('responsive_search_ad_asset_performance_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table) {
            $table->dropIndex(['responsive_search_ad_asset_performance_expires_at']);
            $table->dropColumn(['responsive_search_ad_asset_performance', 'responsive_search_ad_asset_performance_expires_at']);
        });
    }
};
