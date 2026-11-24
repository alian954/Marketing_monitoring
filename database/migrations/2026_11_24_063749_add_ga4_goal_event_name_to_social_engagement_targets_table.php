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
        Schema::table('social_engagement_targets', function (Blueprint $table) {
            $table->string('ga4_goal_event_name', 100)->nullable()->after('ga4_goal_metric');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_engagement_targets', function (Blueprint $table) {
            $table->dropColumn('ga4_goal_event_name');
        });
    }
};
