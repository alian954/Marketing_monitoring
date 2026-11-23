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
            $table->longText('new_vs_returning_report')->nullable();
            $table->timestamp('new_vs_returning_report_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table) {
            $table->dropIndex(['new_vs_returning_report_expires_at']);
            $table->dropColumn(['new_vs_returning_report', 'new_vs_returning_report_expires_at']);
        });
    }
};
