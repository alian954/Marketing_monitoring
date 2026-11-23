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
            $table->longText('placement_insights')->nullable();
            $table->timestamp('placement_insights_expires_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_target_meta_marketing_reports', function (Blueprint $table) {
            $table->dropIndex(['placement_insights_expires_at']);
            $table->dropColumn(['placement_insights', 'placement_insights_expires_at']);
        });
    }
};
