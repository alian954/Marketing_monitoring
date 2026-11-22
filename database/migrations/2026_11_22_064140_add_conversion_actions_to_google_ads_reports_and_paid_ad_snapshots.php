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
            $table->json('conversion_actions')->nullable();
        });

        Schema::table('social_engagement_target_paid_ad_snapshots', function (Blueprint $table) {
            $table->json('conversion_actions')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_paid_ad_snapshots', function (Blueprint $table) {
            $table->dropColumn('conversion_actions');
        });

        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table) {
            $table->dropColumn('conversion_actions');
        });
    }
};
