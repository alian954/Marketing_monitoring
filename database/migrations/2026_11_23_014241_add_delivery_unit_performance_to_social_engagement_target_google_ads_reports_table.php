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
            $table->longText('delivery_unit_performance')->nullable();
            $table->timestamp('delivery_unit_performance_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_google_ads_reports', function (Blueprint $table) {
            $table->dropIndex(['delivery_unit_performance_expires_at']);
            $table->dropColumn(['delivery_unit_performance', 'delivery_unit_performance_expires_at']);
        });
    }
};
