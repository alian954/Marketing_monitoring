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
            $table->longText('event_name_report')->nullable();
            $table->timestamp('event_name_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_analytics_reports', function (Blueprint $table): void {
            $table->dropIndex(['event_name_expires_at']);
            $table->dropColumn(['event_name_report', 'event_name_expires_at']);
        });
    }
};
