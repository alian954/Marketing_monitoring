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
            $table->longText('landing_page_report')->nullable();
            $table->timestamp('landing_page_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropIndex(['landing_page_expires_at']);
            $table->dropColumn(['landing_page_report', 'landing_page_expires_at']);
        });
    }
};
