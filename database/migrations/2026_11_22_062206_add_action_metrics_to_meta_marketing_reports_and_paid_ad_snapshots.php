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
        Schema::table('social_engagement_target_meta_marketing_reports', function (Blueprint $table) {
            $table->json('actions')->nullable();
            $table->json('action_values')->nullable();
        });

        Schema::table('social_engagement_target_paid_ad_snapshots', function (Blueprint $table) {
            $table->json('actions')->nullable();
            $table->json('action_values')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_paid_ad_snapshots', function (Blueprint $table) {
            $table->dropColumn(['actions', 'action_values']);
        });

        Schema::table('social_engagement_target_meta_marketing_reports', function (Blueprint $table) {
            $table->dropColumn(['actions', 'action_values']);
        });
    }
};
