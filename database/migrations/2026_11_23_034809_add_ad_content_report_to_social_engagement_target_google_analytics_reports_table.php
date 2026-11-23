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
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table) {
            $table->longText('ad_content_report')->nullable();
            $table->timestamp('ad_content_report_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table) {
            $table->dropIndex(['ad_content_report_expires_at']);
            $table->dropColumn(['ad_content_report', 'ad_content_report_expires_at']);
        });
    }
};
